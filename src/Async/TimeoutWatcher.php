<?php

declare(strict_types=1);

namespace WireHttp\Async;

use WireHttp\Exceptions\TimeoutException;

/**
 * TimeoutWatcher — Non-Blocking Timeout Tracking for In-Flight Requests
 *
 * The TimeoutWatcher is a lightweight, high-precision component responsible for
 * tracking timeout deadlines for async HTTP requests. It works exclusively with
 * wall-clock time (via `microtime(true)`) and does NOT use PHP's `sleep()`,
 * `usleep()`, or `pcntl_alarm()` — those would block the event loop.
 *
 * How it works:
 * -------------
 * When a request is registered with a timeout:
 *   1. `TimeoutWatcher::watch()` is called with the Future ID and deadline.
 *   2. On every Loop tick, `TimeoutWatcher::checkExpired()` is called.
 *   3. `checkExpired()` compares `microtime(true)` against each deadline.
 *   4. If a deadline has passed, `checkExpired()` returns those entries.
 *   5. The Loop uses those entries to call `FiberManager::reject()` with
 *      a `TimeoutException` for each expired request.
 *
 * Precision:
 * ----------
 * microtime(true) returns a float with microsecond (μs) precision.
 * This is accurate enough for all practical HTTP timeout scenarios.
 * There is a small overhead from the Loop's tick cycle (typically < 1ms),
 * so timeouts may fire slightly AFTER the configured threshold, but never before.
 *
 * Performance:
 * ------------
 * The watcher uses a min-heap-inspired sorted array so that we can quickly
 * determine if ANY timeout has expired without iterating the full list on
 * every tick. When the earliest deadline is in the future, we skip the loop.
 */
final class TimeoutWatcher
{
    /**
     * Map of curlHandleId => TimeoutEntry.
     *
     * @var array<int, TimeoutEntry>
     */
    private array $entries = [];

    /**
     * The earliest deadline across all registered entries, cached for fast checking.
     * If there are no entries, this is PHP_FLOAT_MAX (effectively infinity).
     */
    private float $earliestDeadline = PHP_FLOAT_MAX;

    // ─── Registration ─────────────────────────────────────────────────────────

    /**
     * Registers a timeout deadline for a given request.
     *
     * @param int   $curlHandleId    The cURL handle ID to track.
     * @param float $timeoutSeconds  The total timeout in seconds (fractional supported).
     *                               For example: 2.5 = 2500 milliseconds.
     * @param bool  $isConnect       If true, this is a connect-phase timeout.
     *                               If false, it is a total request timeout.
     */
    public function watch(
        int   $curlHandleId,
        float $timeoutSeconds,
        bool  $isConnect = false,
    ): void {
        if ($timeoutSeconds <= 0.0) {
            return; // No-op: zero or negative timeout = no timeout
        }

        $deadline = microtime(as_float: true) + $timeoutSeconds;

        $this->entries[$curlHandleId] = new TimeoutEntry(
            curlHandleId: $curlHandleId,
            deadline: $deadline,
            configuredTimeoutSeconds: $timeoutSeconds,
            isConnect: $isConnect,
        );

        // Update the cached earliest deadline
        if ($deadline < $this->earliestDeadline) {
            $this->earliestDeadline = $deadline;
        }
    }

    /**
     * Removes the timeout watcher for a given cURL handle.
     * Must be called when a request completes (success or failure) to prevent
     * phantom timeout exceptions from firing after a request has already finished.
     */
    public function unwatch(int $curlHandleId): void
    {
        if (!isset($this->entries[$curlHandleId])) {
            return;
        }

        unset($this->entries[$curlHandleId]);
        $this->recalculateEarliestDeadline();
    }

    // ─── Tick Logic ───────────────────────────────────────────────────────────

    /**
     * Checks all registered timeouts against the current wall-clock time.
     * Returns a list of TimeoutEntries that have expired.
     *
     * This method is designed to be called on EVERY Loop tick. It is optimized
     * to be a near-zero-cost no-op when no timeouts are near expiry:
     *   1. If entries is empty → immediate return []
     *   2. If earliestDeadline > now → immediate return []
     *   3. Only if #2 fails do we iterate the entries array
     *
     * @return list<TimeoutEntry> The list of expired entries (empty if none).
     */
    public function checkExpired(): array
    {
        if (empty($this->entries)) {
            return [];
        }

        $now = microtime(as_float: true);

        // Fast path: if the earliest possible deadline hasn't passed, skip iteration
        if ($this->earliestDeadline > $now) {
            return [];
        }

        $expired = [];

        foreach ($this->entries as $entry) {
            if ($entry->deadline <= $now) {
                $expired[] = $entry;
                unset($this->entries[$entry->curlHandleId]);
            }
        }

        if (!empty($expired)) {
            $this->recalculateEarliestDeadline();
        }

        return $expired;
    }

    /**
     * Returns the number of actively watched timeouts.
     */
    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * Returns true if there are no active timeout watchers.
     */
    public function isEmpty(): bool
    {
        return empty($this->entries);
    }

    /**
     * Returns how many seconds remain until the earliest registered deadline.
     * Returns 0.0 if any deadline has already passed.
     * Returns PHP_FLOAT_MAX if there are no registered timeouts.
     *
     * The Loop uses this to calculate the maximum safe `curl_multi_select()` wait
     * interval without risking missing a timeout.
     */
    public function secondsUntilEarliestDeadline(): float
    {
        if (empty($this->entries)) {
            return PHP_FLOAT_MAX;
        }

        return max(0.0, $this->earliestDeadline - microtime(as_float: true));
    }

    /**
     * Recalculates and caches the earliest deadline in the entries map.
     * Called after any removal to keep the fast-path check accurate.
     */
    private function recalculateEarliestDeadline(): void
    {
        if (empty($this->entries)) {
            $this->earliestDeadline = PHP_FLOAT_MAX;

            return;
        }

        $min = PHP_FLOAT_MAX;

        foreach ($this->entries as $entry) {
            if ($entry->deadline < $min) {
                $min = $entry->deadline;
            }
        }

        $this->earliestDeadline = $min;
    }
}

/**
 * TimeoutEntry — Immutable Record of a Single Timeout Registration
 *
 * @internal
 */
final class TimeoutEntry
{
    public function __construct(
        public readonly int   $curlHandleId,
        public readonly float $deadline,                  // microtime(true) when this expires
        public readonly float $configuredTimeoutSeconds,  // What the developer configured
        public readonly bool  $isConnect,                 // true = connect timeout, false = total timeout
    ) {
    }

    /**
     * Builds a TimeoutException from this entry's data.
     * Used by the Loop when rejecting a timed-out FiberEntry.
     */
    public function toException(): TimeoutException
    {
        $elapsed = microtime(as_float: true) - ($this->deadline - $this->configuredTimeoutSeconds);

        return new TimeoutException(
            connectTimeout: $this->isConnect,
            configuredTimeoutSeconds: $this->configuredTimeoutSeconds,
            elapsedSeconds: round($elapsed, 4),
        );
    }
}
