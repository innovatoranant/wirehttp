<?php

declare(strict_types=1);

namespace WireHttp\Middleware\Core;

use WireHttp\Http\Request;
use WireHttp\Http\Response;
use WireHttp\Middleware\MiddlewareInterface;
use WireHttp\Exceptions\CircuitBreakerOpenException;

/**
 * CircuitBreakerInterceptor — Resilience Pattern for Failing Services
 *
 * The Circuit Breaker pattern prevents cascading failures when a downstream
 * service is unhealthy. Instead of hammering a failing server with requests
 * that will time out, the circuit "opens" and subsequent requests fail
 * immediately without touching the network.
 *
 * How it Works:
 * -------------
 * The circuit breaker has three states:
 *
 *   ┌─────────┐                ┌──────────┐
 *   │  CLOSED  │  N failures →  │   OPEN   │
 *   │(normal)  │                │(blocked) │
 *   └─────────┘                └──────────┘
 *        ↑                           │
 *        │        cooldown expires   │
 *        │                           ↓
 *   success      ←─────────   ┌──────────────┐
 *                              │  HALF-OPEN   │
 *                              │ (probe sent) │
 *                              └──────────────┘
 *
 * CLOSED (normal operation):
 *   - All requests flow through normally.
 *   - Failures are counted. If failures reach `$failureThreshold` within
 *     `$windowSeconds`, the circuit OPENS.
 *
 * OPEN (blocking):
 *   - No requests reach the network.
 *   - Every call immediately throws a `CircuitOpenException`.
 *   - After `$cooldownSeconds` have passed, the circuit transitions to HALF-OPEN.
 *
 * HALF-OPEN (recovery probe):
 *   - ONE request is allowed through as a probe.
 *   - If it succeeds: circuit transitions back to CLOSED (reset failure count).
 *   - If it fails: circuit returns to OPEN (another full cooldown period).
 *
 * Per-Domain Isolation:
 * ---------------------
 * The circuit breaker tracks failure state INDEPENDENTLY per domain.
 * A failing `payments.example.com` does not trip the circuit for `cdn.example.com`.
 * The domain key is "scheme://host:port" for precise matching.
 *
 * Usage:
 *   $breaker = new CircuitBreakerInterceptor(
 *       failureThreshold: 5,       // Open after 5 failures
 *       windowSeconds: 30.0,       // ...within 30 seconds
 *       cooldownSeconds: 60.0,     // Stay open for 60 seconds
 *       successThreshold: 2,       // Close after 2 consecutive successes in HALF-OPEN
 *   );
 *   $client = new Client(middleware: [$breaker]);
 */
final class CircuitBreakerInterceptor implements MiddlewareInterface
{
    private const STATE_CLOSED    = 'closed';
    private const STATE_OPEN      = 'open';
    private const STATE_HALF_OPEN = 'half-open';

    /**
     * Number of failures within $windowSeconds to trip the circuit.
     */
    private readonly int $failureThreshold;

    /**
     * The sliding window in seconds over which failures are counted.
     */
    private readonly float $windowSeconds;

    /**
     * How long the circuit stays OPEN before transitioning to HALF-OPEN.
     */
    private readonly float $cooldownSeconds;

    /**
     * Number of consecutive successes in HALF-OPEN state to close the circuit.
     */
    private readonly int $successThreshold;

    /**
     * HTTP status codes that count as failures (in addition to exceptions).
     * 5xx by default.
     *
     * @var list<int>
     */
    private readonly array $failureStatusCodes;

    /**
     * Per-domain circuit state.
     * Structure: domain_key => CircuitState
     *
     * @var array<string, CircuitState>
     */
    private array $circuits = [];

    /**
     * Callback invoked when the circuit state changes.
     * Signature: callable(string $domain, string $oldState, string $newState): void
     */
    private readonly ?\Closure $onStateChange;

    public function __construct(
        int     $failureThreshold = 5,
        float   $windowSeconds = 30.0,
        float   $cooldownSeconds = 60.0,
        int     $successThreshold = 1,
        array   $failureStatusCodes = [500, 502, 503, 504],
        ?\Closure $onStateChange = null,
    ) {
        $this->failureThreshold   = $failureThreshold;
        $this->windowSeconds      = $windowSeconds;
        $this->cooldownSeconds    = $cooldownSeconds;
        $this->successThreshold   = $successThreshold;
        $this->failureStatusCodes = $failureStatusCodes;
        $this->onStateChange      = $onStateChange;
    }

    public function process(Request $request, callable $next): Response
    {
        $key     = $this->getDomainKey($request);
        $circuit = $this->getOrCreateCircuit($key);

        // ── OPEN: Fast-fail immediately ───────────────────────────────────────
        if ($circuit->state === self::STATE_OPEN) {
            $elapsed = microtime(as_float: true) - $circuit->openedAt;

            if ($elapsed < $this->cooldownSeconds) {
                $remaining = round($this->cooldownSeconds - $elapsed, 1);

                throw new CircuitBreakerOpenException(
                    sprintf(
                        'Circuit breaker OPEN for "%s". Service unavailable. ' .
                        'Circuit will probe again in %.1f seconds.',
                        $key,
                        $remaining
                    )
                );
            }

            // Cooldown expired — transition to HALF-OPEN
            $this->transitionTo($key, self::STATE_HALF_OPEN);
        }

        // ── HALF-OPEN: Allow ONE probe request ────────────────────────────────
        if ($circuit->state === self::STATE_HALF_OPEN) {
            if ($circuit->probeInFlight) {
                // Another probe is already in flight — fast-fail this request too
                throw new \RuntimeException(
                    sprintf('Circuit breaker HALF-OPEN for "%s". Probe already in flight.', $key)
                );
            }

            $circuit->probeInFlight = true;
        }

        // ── CLOSED / HALF-OPEN probe: Execute the request ─────────────────────
        try {
            $response = $next($request);

            // Check if the response status counts as a failure
            if (in_array($response->getStatusCode(), $this->failureStatusCodes, strict: true)) {
                $this->recordFailure($key, $circuit);
            } else {
                $this->recordSuccess($key, $circuit);
            }

            return $response;

        } catch (\Throwable $exception) {
            $this->recordFailure($key, $circuit);

            throw $exception;
        }
    }

    // ─── State Machine ────────────────────────────────────────────────────────

    private function recordSuccess(string $key, CircuitState $circuit): void
    {
        if ($circuit->state === self::STATE_HALF_OPEN) {
            $circuit->probeInFlight       = false;
            $circuit->consecutiveSuccesses++;

            if ($circuit->consecutiveSuccesses >= $this->successThreshold) {
                // Recovered! Close the circuit.
                $this->transitionTo($key, self::STATE_CLOSED);
            }

            return;
        }

        // In CLOSED state, reset failure timestamps on success (sliding window)
        // Actually we just record — the window slide happens in recordFailure
    }

    private function recordFailure(string $key, CircuitState $circuit): void
    {
        $now = microtime(as_float: true);

        if ($circuit->state === self::STATE_HALF_OPEN) {
            // Probe failed — re-open the circuit
            $circuit->probeInFlight       = false;
            $circuit->consecutiveSuccesses = 0;
            $this->transitionTo($key, self::STATE_OPEN);

            return;
        }

        // Record failure timestamp (sliding window)
        $circuit->failureTimestamps[] = $now;

        // Purge timestamps outside the window
        $windowStart               = $now - $this->windowSeconds;
        $circuit->failureTimestamps = array_values(
            array_filter($circuit->failureTimestamps, static fn(float $t) => $t >= $windowStart)
        );

        // Check if we've hit the failure threshold
        if (count($circuit->failureTimestamps) >= $this->failureThreshold) {
            $this->transitionTo($key, self::STATE_OPEN);
        }
    }

    private function transitionTo(string $key, string $newState): void
    {
        $circuit    = $this->circuits[$key];
        $oldState   = $circuit->state;

        $circuit->state = $newState;

        if ($newState === self::STATE_OPEN) {
            $circuit->openedAt              = microtime(as_float: true);
            $circuit->consecutiveSuccesses  = 0;
        } elseif ($newState === self::STATE_CLOSED) {
            $circuit->failureTimestamps     = [];
            $circuit->consecutiveSuccesses  = 0;
            $circuit->openedAt              = 0.0;
            $circuit->probeInFlight         = false;
        } elseif ($newState === self::STATE_HALF_OPEN) {
            $circuit->probeInFlight = false;
        }

        if ($this->onStateChange !== null && $oldState !== $newState) {
            ($this->onStateChange)($key, $oldState, $newState);
        }
    }

    // ─── Introspection ────────────────────────────────────────────────────────

    /**
     * Returns the current state of the circuit for a specific domain.
     * Returns null if no circuit has been created for that domain yet.
     */
    public function getState(string $domainKey): ?string
    {
        return $this->circuits[$domainKey]?->state ?? null;
    }

    /**
     * Returns a snapshot of all circuit states for monitoring.
     *
     * @return array<string, array{state: string, failures: int, opened_at: float}>
     */
    public function getAllStates(): array
    {
        $snapshot = [];

        foreach ($this->circuits as $key => $circuit) {
            $snapshot[$key] = [
                'state'     => $circuit->state,
                'failures'  => count($circuit->failureTimestamps),
                'opened_at' => $circuit->openedAt,
            ];
        }

        return $snapshot;
    }

    /**
     * Manually forces a circuit to CLOSED state.
     * Use for admin-level intervention or after a known deployment fix.
     */
    public function forceClose(string $domainKey): void
    {
        if (isset($this->circuits[$domainKey])) {
            $this->transitionTo($domainKey, self::STATE_CLOSED);
        }
    }

    /**
     * Manually forces a circuit to OPEN state.
     * Use for maintenance windows or known-down services.
     */
    public function forceOpen(string $domainKey): void
    {
        $circuit = $this->getOrCreateCircuit($domainKey);
        $this->transitionTo($domainKey, self::STATE_OPEN);
    }

    // ─── Private Helpers ─────────────────────────────────────────────────────

    private function getOrCreateCircuit(string $key): CircuitState
    {
        if (!isset($this->circuits[$key])) {
            $this->circuits[$key] = new CircuitState();
        }

        return $this->circuits[$key];
    }

    private function getDomainKey(Request $request): string
    {
        $uri  = $request->getUri();
        $port = $uri->getEffectivePort() ?? 80;

        return sprintf('%s://%s:%d', $uri->getScheme(), $uri->getHost(), $port);
    }
}

/**
 * CircuitState — Mutable state for a single domain's circuit breaker.
 *
 * @internal
 */
final class CircuitState
{
    public string $state = 'closed';

    /** @var list<float> microtime timestamps of recent failures */
    public array $failureTimestamps = [];

    /** microtime when the circuit was opened */
    public float $openedAt = 0.0;

    /** consecutive successes in HALF-OPEN state */
    public int $consecutiveSuccesses = 0;

    /** true while a HALF-OPEN probe request is in flight */
    public bool $probeInFlight = false;
}
