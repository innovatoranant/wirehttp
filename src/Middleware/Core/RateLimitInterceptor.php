<?php

declare(strict_types=1);

namespace WireHttp\Middleware\Core;

use WireHttp\Http\Request;
use WireHttp\Http\Response;
use WireHttp\Middleware\MiddlewareInterface;

/**
 * RateLimitInterceptor — Proactive & Reactive Rate Limit Management
 *
 * Manages API rate limits using two complementary strategies:
 *
 * 1. REACTIVE (Server-Driven):
 *    Detects 429 Too Many Requests responses and backs off for the duration
 *    specified by the server's `Retry-After` header. This is the minimum viable
 *    rate limiting — it does what the server tells you.
 *
 * 2. PROACTIVE (Client-Driven, Leaky Bucket Algorithm):
 *    Limits outgoing requests to at most $maxRequestsPerWindow requests per
 *    $windowSeconds sliding window. This prevents the server from ever seeing
 *    429s in the first place — ideal for APIs with known rate limits.
 *
 * The Leaky Bucket Algorithm:
 * ---------------------------
 * We track timestamps of the last N requests. On each new request:
 *   1. Remove timestamps older than the window boundary.
 *   2. If the bucket is full (count == maxRequestsPerWindow):
 *      - Calculate how long until the oldest request falls outside the window.
 *      - Sleep for that duration (proactive throttling).
 *   3. Record the current timestamp and proceed.
 *
 * This gives a smooth, rolling rate limit rather than a "reset at midnight" burst.
 *
 * Per-Domain Tracking:
 * --------------------
 * Rate limits are tracked per-domain. This means a rate limit for api.example.com
 * does not affect requests to api2.example.com. The domain key is "scheme://host:port".
 *
 * Usage:
 *   // Proactive: max 100 requests per 60 seconds (per domain)
 *   $limiter = new RateLimitInterceptor(
 *       maxRequestsPerWindow: 100,
 *       windowSeconds: 60.0,
 *   );
 *
 *   // Reactive only (no proactive throttling)
 *   $limiter = new RateLimitInterceptor();
 */
final class RateLimitInterceptor implements MiddlewareInterface
{
    /**
     * Maximum requests allowed per window (per domain).
     * 0 = disabled (reactive only).
     */
    private readonly int $maxRequestsPerWindow;

    /**
     * Duration of the sliding time window in seconds.
     */
    private readonly float $windowSeconds;

    /**
     * Per-domain timestamp ring buffers for the leaky bucket algorithm.
     * Structure: domain_key => list of microtime floats
     *
     * @var array<string, list<float>>
     */
    private array $buckets = [];

    /**
     * If we receive a 429, we wait this long MINIMUM even if Retry-After says less.
     */
    private readonly float $minBackoffSeconds;

    /**
     * Maximum backoff we'll honor from Retry-After. Anything longer is capped.
     */
    private readonly float $maxBackoffSeconds;

    /**
     * Callback invoked when we throttle a request (for logging/metrics).
     * Signature: callable(Request $request, float $delaySeconds): void
     */
    private readonly ?\Closure $onThrottle;

    public function __construct(
        int     $maxRequestsPerWindow = 0,
        float   $windowSeconds = 60.0,
        float   $minBackoffSeconds = 1.0,
        float   $maxBackoffSeconds = 300.0,
        ?\Closure $onThrottle = null,
    ) {
        $this->maxRequestsPerWindow = $maxRequestsPerWindow;
        $this->windowSeconds        = $windowSeconds;
        $this->minBackoffSeconds    = $minBackoffSeconds;
        $this->maxBackoffSeconds    = $maxBackoffSeconds;
        $this->onThrottle           = $onThrottle;
    }

    public function process(Request $request, callable $next): Response
    {
        // ── Proactive Rate Limiting ────────────────────────────────────────────
        if ($this->maxRequestsPerWindow > 0) {
            $this->proactiveThrottle($request);
        }

        // ── Send the request ──────────────────────────────────────────────────
        $response = $next($request);

        // ── Reactive: Handle 429 Too Many Requests ────────────────────────────
        if ($response->isTooManyRequests()) {
            $backoff = $this->calculate429Backoff($response);

            if ($this->onThrottle !== null) {
                ($this->onThrottle)($request, $backoff);
            }

            if ($backoff > 0) {
                usleep((int) ($backoff * 1_000_000));
            }

            // After backing off, re-execute the request by calling $next again
            // Note: This does NOT loop forever — if we get another 429, we
            // return it. For repeated 429s, pair with RetryInterceptor.
            return $next($request);
        }

        return $response;
    }

    // ─── Proactive Leaky Bucket Logic ─────────────────────────────────────────

    /**
     * Blocks the current request until it can proceed within the rate limit.
     */
    private function proactiveThrottle(Request $request): void
    {
        $key = $this->getDomainKey($request);
        $now = microtime(as_float: true);

        // Initialize or reference the bucket for this domain
        if (!isset($this->buckets[$key])) {
            $this->buckets[$key] = [];
        }

        $bucket     = &$this->buckets[$key];
        $windowStart = $now - $this->windowSeconds;

        // Remove timestamps that have fallen outside the current window
        $bucket = array_values(array_filter($bucket, static fn(float $t) => $t > $windowStart));

        // If the bucket is full, we need to wait
        if (count($bucket) >= $this->maxRequestsPerWindow) {
            // The oldest request in the bucket: how long until it exits the window?
            $oldestTimestamp   = $bucket[0];
            $waitUntil         = $oldestTimestamp + $this->windowSeconds;
            $sleepSeconds      = $waitUntil - $now;

            if ($sleepSeconds > 0) {
                if ($this->onThrottle !== null) {
                    ($this->onThrottle)($request, $sleepSeconds);
                }

                usleep((int) ($sleepSeconds * 1_000_000));
            }

            // Re-prune after sleeping
            $now        = microtime(as_float: true);
            $windowStart = $now - $this->windowSeconds;
            $bucket     = array_values(array_filter($bucket, static fn(float $t) => $t > $windowStart));
        }

        // Record this request's timestamp
        $bucket[] = microtime(as_float: true);
    }

    // ─── Reactive 429 Logic ───────────────────────────────────────────────────

    /**
     * Calculates how long to back off after a 429 response.
     *
     * Priority order:
     *   1. Retry-After header (server-specified, most authoritative)
     *   2. X-RateLimit-Reset header (some APIs use this instead)
     *   3. minBackoffSeconds (our configured minimum)
     */
    private function calculate429Backoff(Response $response): float
    {
        // Standard Retry-After header (RFC 7231)
        $retryAfter = $response->getRetryAfterSeconds();

        if ($retryAfter !== null) {
            return (float) max($this->minBackoffSeconds, min($retryAfter, $this->maxBackoffSeconds));
        }

        // X-RateLimit-Reset (GitHub, Twitter, etc.) — Unix timestamp
        $resetHeader = $response->getHeaderLine('X-RateLimit-Reset');

        if ($resetHeader !== '' && is_numeric($resetHeader)) {
            $resetAt = (int) $resetHeader;
            $waitFor = $resetAt - time();

            if ($waitFor > 0) {
                return (float) min($waitFor, $this->maxBackoffSeconds);
            }
        }

        // Fallback to configured minimum
        return $this->minBackoffSeconds;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Returns a unique key for the request's domain for per-domain rate limiting.
     * Format: "scheme://host:port"
     */
    private function getDomainKey(Request $request): string
    {
        $uri  = $request->getUri();
        $port = $uri->getEffectivePort() ?? 80;

        return sprintf('%s://%s:%d', $uri->getScheme(), $uri->getHost(), $port);
    }

    /**
     * Returns a snapshot of the rate limit state for each tracked domain.
     * Useful for monitoring/debugging.
     *
     * @return array<string, array{requests_in_window: int, window_seconds: float}>
     */
    public function getStats(): array
    {
        $now        = microtime(as_float: true);
        $stats      = [];

        foreach ($this->buckets as $key => $timestamps) {
            $windowStart           = $now - $this->windowSeconds;
            $inWindow              = array_filter($timestamps, static fn(float $t) => $t > $windowStart);
            $stats[$key]           = [
                'requests_in_window' => count($inWindow),
                'window_seconds'     => $this->windowSeconds,
                'limit'              => $this->maxRequestsPerWindow,
            ];
        }

        return $stats;
    }

    /**
     * Resets the rate limit state for all domains.
     * Useful for testing or after a known rate-limit window expires.
     */
    public function reset(): void
    {
        $this->buckets = [];
    }
}
