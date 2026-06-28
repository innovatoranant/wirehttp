<?php

declare(strict_types=1);

namespace WireHttp\Middleware\Core;

use WireHttp\Exceptions\NetworkException;
use WireHttp\Exceptions\ServerException;
use WireHttp\Exceptions\TimeoutException;
use WireHttp\Http\Request;
use WireHttp\Http\Response;
use WireHttp\Middleware\MiddlewareInterface;

/**
 * RetryInterceptor — Exponential Backoff with Full Jitter
 *
 * Automatically retries failed HTTP requests using an exponential backoff
 * algorithm with "full jitter" — the industry-standard retry strategy
 * that prevents the "thundering herd" problem.
 *
 * What is "thundering herd"?
 * --------------------------
 * If 1000 clients all retry at exactly the same time (e.g., every 2 seconds),
 * the retries arrive as a massive spike, potentially overwhelming the server
 * even further. Jitter randomizes the retry delay so clients spread out.
 *
 * Algorithm: "Full Jitter" (recommended by AWS, Netflix, Google):
 * ---------------------------------------------------------------
 *   base_delay = min(cap, base * 2^attempt)
 *   sleep      = random_between(0, base_delay)
 *
 * Where:
 *   base     = Initial delay in seconds (default: 0.5s)
 *   cap      = Maximum delay in seconds (default: 30s)
 *   attempt  = Retry attempt number (0-indexed)
 *
 * Example with base=0.5, cap=30:
 *   Attempt 0: delay = random(0, 0.5s)
 *   Attempt 1: delay = random(0, 1.0s)
 *   Attempt 2: delay = random(0, 2.0s)
 *   Attempt 3: delay = random(0, 4.0s)
 *   Attempt 4: delay = random(0, 8.0s)
 *   Attempt 5: delay = random(0, 16s)
 *   Attempt 6+: delay = random(0, 30s)  [capped]
 *
 * What triggers a retry?
 * ----------------------
 * By default, the RetryInterceptor retries:
 *   - NetworkException (connection failed, DNS error)
 *   - TimeoutException (request timed out)
 *   - HTTP 429 Too Many Requests (with Retry-After header respected)
 *   - HTTP 500, 502, 503, 504 (transient server errors)
 *
 * What does NOT trigger a retry (by default):
 *   - 4xx client errors (400, 401, 403, 404) — these are permanent failures.
 *   - 200–299 success responses.
 *
 * Retry conditions are fully customizable via the $shouldRetry callback.
 *
 * Usage:
 *   $retrier = new RetryInterceptor(
 *       maxAttempts: 3,
 *       baseDelaySeconds: 0.5,
 *       maxDelaySeconds: 30.0,
 *   );
 *   $client = new Client(middleware: [$retrier]);
 */
final class RetryInterceptor implements MiddlewareInterface
{
    /**
     * HTTP status codes that are considered retryable server errors.
     */
    private const RETRYABLE_STATUS_CODES = [429, 500, 502, 503, 504];

    /**
     * Maximum number of total attempts (1 initial + N retries).
     * `maxAttempts: 3` means 1 initial attempt + 2 retries = 3 total tries.
     */
    private readonly int $maxAttempts;

    /**
     * Base delay in seconds for exponential backoff (before jitter).
     */
    private readonly float $baseDelaySeconds;

    /**
     * Maximum delay in seconds (caps the exponential growth).
     */
    private readonly float $maxDelaySeconds;

    /**
     * Optional custom callback to determine if a request/response should be retried.
     * Signature: callable(Request, Response|null, \Throwable|null, int $attempt): bool
     *   - $response is null if an exception was thrown (no response received).
     *   - $exception is null if a response was received (even a 5xx response).
     *   - $attempt is 0-indexed (0 = first retry, 1 = second retry, etc.)
     */
    private readonly ?\Closure $shouldRetry;

    /**
     * Optional callback called before each retry, useful for logging/metrics.
     * Signature: callable(Request, Response|null, \Throwable|null, int $attempt, float $delaySeconds): void
     */
    private readonly ?\Closure $onRetry;

    public function __construct(
        int     $maxAttempts = 3,
        float   $baseDelaySeconds = 0.5,
        float   $maxDelaySeconds = 30.0,
        ?\Closure $shouldRetry = null,
        ?\Closure $onRetry = null,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be at least 1.');
        }

        $this->maxAttempts     = $maxAttempts;
        $this->baseDelaySeconds = $baseDelaySeconds;
        $this->maxDelaySeconds  = $maxDelaySeconds;
        $this->shouldRetry     = $shouldRetry;
        $this->onRetry         = $onRetry;
    }

    public function process(Request $request, callable $next): Response
    {
        $attempt   = 0;
        $lastError = null;

        while ($attempt < $this->maxAttempts) {
            $response  = null;
            $exception = null;

            try {
                $response = $next($request);
            } catch (NetworkException | TimeoutException $e) {
                $exception = $e;
                $lastError = $e;
            }

            // ── Determine if we should retry ──────────────────────────────────
            $shouldRetry = $this->determineShouldRetry(
                request: $request,
                response: $response,
                exception: $exception,
                retryAttempt: $attempt,
            );

            if (!$shouldRetry || $attempt === $this->maxAttempts - 1) {
                // No more retries — return or throw
                if ($exception !== null) {
                    throw $exception;
                }

                return $response;
            }

            // ── Calculate delay ───────────────────────────────────────────────
            $delay = $this->calculateDelay($attempt, $response);

            // ── Fire onRetry callback (for logging/metrics) ───────────────────
            if ($this->onRetry !== null) {
                ($this->onRetry)($request, $response, $exception, $attempt, $delay);
            }

            // ── Wait (non-blocking micro-sleep) ───────────────────────────────
            if ($delay > 0) {
                usleep((int) ($delay * 1_000_000));
            }

            $attempt++;
        }

        // This should never be reached, but type-safety requires a return/throw
        throw $lastError ?? new \RuntimeException('RetryInterceptor: exhausted all attempts.');
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Determines whether the current request/response should be retried.
     */
    private function determineShouldRetry(
        Request    $request,
        ?Response  $response,
        ?\Throwable $exception,
        int        $retryAttempt,
    ): bool {
        // Use custom callback if provided
        if ($this->shouldRetry !== null) {
            return (bool) ($this->shouldRetry)($request, $response, $exception, $retryAttempt);
        }

        // Default retry logic:
        // 1. Any network-level exception → retry
        if ($exception instanceof NetworkException || $exception instanceof TimeoutException) {
            return true;
        }

        // 2. Retryable HTTP status codes → retry
        if ($response !== null && in_array($response->getStatusCode(), self::RETRYABLE_STATUS_CODES, strict: true)) {
            return true;
        }

        return false;
    }

    /**
     * Calculates the delay for this retry attempt using Full Jitter Exponential Backoff.
     *
     * Special case: 429 Too Many Requests with Retry-After header.
     * We MUST respect the Retry-After value — the server is telling us exactly
     * how long to wait. Ignoring it can get our IP banned.
     *
     * @param int       $attempt  The 0-indexed retry attempt number.
     * @param Response|null $response The last response (to check for Retry-After).
     * @return float The delay in seconds (may be fractional).
     */
    private function calculateDelay(int $attempt, ?Response $response): float
    {
        // Check for server-specified Retry-After header (429, 503)
        if ($response !== null) {
            $serverDelay = $response->getRetryAfterSeconds();

            if ($serverDelay !== null && $serverDelay > 0) {
                // Respect server's instruction, but cap at maxDelaySeconds
                return min($serverDelay, $this->maxDelaySeconds);
            }
        }

        // Full Jitter Exponential Backoff formula:
        //   base_delay = min(cap, base * 2^attempt)
        //   sleep      = random(0, base_delay)
        $baseDelay = min(
            $this->maxDelaySeconds,
            $this->baseDelaySeconds * (2 ** $attempt)
        );

        // random_int(0, max * 1000) / 1000 gives us millisecond precision
        $randomFactor = random_int(0, (int) ($baseDelay * 1000));

        return $randomFactor / 1000.0;
    }

    /**
     * Creates a RetryInterceptor configured for aggressive retry (API polling).
     * 5 attempts with very short delays.
     */
    public static function aggressive(): static
    {
        return new static(maxAttempts: 5, baseDelaySeconds: 0.1, maxDelaySeconds: 5.0);
    }

    /**
     * Creates a RetryInterceptor configured for conservative retry (expensive operations).
     * 3 attempts with longer delays.
     */
    public static function conservative(): static
    {
        return new static(maxAttempts: 3, baseDelaySeconds: 2.0, maxDelaySeconds: 60.0);
    }

    /**
     * Creates a RetryInterceptor that only retries on network errors (not HTTP errors).
     * Useful when 5xx responses should propagate immediately.
     */
    public static function networkErrorsOnly(int $maxAttempts = 3): static
    {
        return new static(
            maxAttempts: $maxAttempts,
            shouldRetry: static function (Request $r, ?Response $response, ?\Throwable $e): bool {
                return $e instanceof NetworkException || $e instanceof TimeoutException;
            },
        );
    }
}
