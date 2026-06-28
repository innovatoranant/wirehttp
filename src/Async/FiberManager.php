<?php

declare(strict_types=1);

namespace WireHttp\Async;

use WireHttp\Http\Request;

/**
 * FiberManager — PHP 8.1+ Green-Thread Lifecycle Tracker
 *
 * The FiberManager is the brain of WireHTTP's concurrency model. It tracks
 * every active Fiber created for async HTTP requests, manages their state
 * transitions, and provides the Loop with the data it needs to efficiently
 * resume the right Fiber at the right time.
 *
 * Architecture:
 * -------------
 * When an async request is sent (`Wire::get('/api')->sendAsync()`), the
 * CurlMultiHandler creates a new entry in the FiberManager. Each entry
 * tracks:
 *   1. The PHP Fiber object (green-thread)
 *   2. The Deferred (used to resolve/reject the Future when response arrives)
 *   3. The Request (for error context and debugging)
 *   4. The cURL handle ID (to map from curl_multi results back to the right Fiber)
 *   5. The Fiber's creation timestamp (for TimeoutWatcher)
 *
 * The Loop calls `FiberManager::tick()` on every iteration. The FiberManager:
 *   1. Iterates over all pending entries
 *   2. Checks if any entry's Fiber has been resolved (i.e., its cURL result is ready)
 *   3. Resumes those Fibers
 *   4. Cleans up terminated entries
 *
 * State Machine per Entry:
 *   CREATED   → The Fiber has been created but not yet started.
 *   STARTED   → The Fiber has been started and is waiting for a response.
 *   SUSPENDED → The Fiber has called `Fiber::suspend()` and is waiting for the Loop.
 *   RESOLVED  → The cURL result arrived; the Deferred was resolved; Fiber resumed.
 *   TERMINATED → The Fiber has finished execution (success or failure).
 */
final class FiberManager
{
    /**
     * Map of cURL handle IDs to FiberEntry objects.
     * We key by cURL handle ID so the Loop can do O(1) lookups when a
     * curl_multi result arrives.
     *
     * @var array<int, FiberEntry>
     */
    private array $entries = [];

    /**
     * Map of Future IDs to cURL handle IDs.
     * Allows looking up a FiberEntry by Future ID (for cancellation etc).
     *
     * @var array<string, int>
     */
    private array $futureIndex = [];

    /**
     * Total number of requests that have been registered.
     * Used for diagnostics / metrics.
     */
    private int $totalRegistered = 0;

    /**
     * Total number of requests that have been completed (resolved or rejected).
     */
    private int $totalCompleted = 0;

    // ─── Registration ─────────────────────────────────────────────────────────

    /**
     * Registers a new Fiber-based async request into the manager.
     *
     * @param int      $curlHandleId The integer ID from `spl_object_id($curlHandle)`.
     * @param \Fiber   $fiber        The PHP Fiber that will execute this request.
     * @param Deferred $deferred     The Deferred to resolve/reject with the result.
     * @param Request  $request      The original HTTP request (for error context).
     * @param float    $timeoutAt    Microtime float after which the request times out.
     *                               Use 0.0 for no timeout.
     */
    public function register(
        int      $curlHandleId,
        \Fiber   $fiber,
        Deferred $deferred,
        Request  $request,
        float    $timeoutAt = 0.0,
    ): void {
        $this->entries[$curlHandleId] = new FiberEntry(
            curlHandleId: $curlHandleId,
            fiber: $fiber,
            deferred: $deferred,
            request: $request,
            timeoutAt: $timeoutAt,
        );

        $this->futureIndex[$deferred->getId()] = $curlHandleId;
        $this->totalRegistered++;
    }

    /**
     * Returns the FiberEntry for a given cURL handle ID.
     * Returns null if no entry is registered for that ID.
     */
    public function getByHandleId(int $curlHandleId): ?FiberEntry
    {
        return $this->entries[$curlHandleId] ?? null;
    }

    /**
     * Returns the FiberEntry for a given Future ID.
     * Returns null if no entry is registered for that ID.
     */
    public function getByFutureId(string $futureId): ?FiberEntry
    {
        $handleId = $this->futureIndex[$futureId] ?? null;

        if ($handleId === null) {
            return null;
        }

        return $this->entries[$handleId] ?? null;
    }

    // ─── Lifecycle Transitions ────────────────────────────────────────────────

    /**
     * Marks a Fiber as successfully resolved and triggers Deferred resolution.
     * The Deferred will automatically resume any waiting Fiber.
     *
     * @param int   $curlHandleId The ID of the cURL handle that completed.
     * @param mixed $value        The resolved value (typically a Response).
     */
    public function resolve(int $curlHandleId, mixed $value): void
    {
        $entry = $this->entries[$curlHandleId] ?? null;

        if ($entry === null) {
            return;
        }

        $entry->deferred->tryResolve($value);
        $this->cleanup($curlHandleId);
    }

    /**
     * Marks a Fiber as rejected and triggers Deferred rejection.
     * The Deferred will automatically throw the exception in any waiting Fiber.
     *
     * @param int        $curlHandleId The ID of the cURL handle that failed.
     * @param \Throwable $reason       The exception to throw.
     */
    public function reject(int $curlHandleId, \Throwable $reason): void
    {
        $entry = $this->entries[$curlHandleId] ?? null;

        if ($entry === null) {
            return;
        }

        $entry->deferred->tryReject($reason);
        $this->cleanup($curlHandleId);
    }

    /**
     * Removes a registered entry from the manager.
     * Called after resolve/reject to prevent memory leaks.
     */
    public function cleanup(int $curlHandleId): void
    {
        $entry = $this->entries[$curlHandleId] ?? null;

        if ($entry === null) {
            return;
        }

        unset(
            $this->futureIndex[$entry->deferred->getId()],
            $this->entries[$curlHandleId]
        );

        $this->totalCompleted++;
    }

    // ─── Timeout Checking ─────────────────────────────────────────────────────

    /**
     * Returns all FiberEntries that have exceeded their timeout threshold.
     * Called by the Loop on every tick to detect and reject timed-out requests.
     *
     * @return list<FiberEntry>
     */
    public function getTimedOutEntries(): array
    {
        if (empty($this->entries)) {
            return [];
        }

        $now     = microtime(as_float: true);
        $expired = [];

        foreach ($this->entries as $entry) {
            if ($entry->timeoutAt > 0.0 && $now >= $entry->timeoutAt) {
                $expired[] = $entry;
            }
        }

        return $expired;
    }

    // ─── Introspection ────────────────────────────────────────────────────────

    /**
     * Returns all currently registered (pending) FiberEntries.
     *
     * @return array<int, FiberEntry>
     */
    public function getPending(): array
    {
        return $this->entries;
    }

    /**
     * Returns the number of currently pending (in-flight) async requests.
     */
    public function pendingCount(): int
    {
        return count($this->entries);
    }

    /**
     * Returns true if there are no pending async requests.
     */
    public function isEmpty(): bool
    {
        return empty($this->entries);
    }

    /**
     * Returns true if a specific cURL handle ID is currently tracked.
     */
    public function has(int $curlHandleId): bool
    {
        return isset($this->entries[$curlHandleId]);
    }

    /**
     * Returns a snapshot of FiberManager statistics for monitoring/debugging.
     *
     * @return array{pending: int, total_registered: int, total_completed: int}
     */
    public function getStats(): array
    {
        return [
            'pending'           => $this->pendingCount(),
            'total_registered'  => $this->totalRegistered,
            'total_completed'   => $this->totalCompleted,
        ];
    }

    /**
     * Forcefully rejects all pending entries with a given exception.
     * Used when shutting down the Loop unexpectedly or during process termination.
     */
    public function rejectAll(\Throwable $reason): void
    {
        foreach (array_keys($this->entries) as $curlHandleId) {
            $this->reject($curlHandleId, $reason);
        }
    }
}

/**
 * FiberEntry — Value Object Tracking a Single In-Flight Async Request
 *
 * This is intentionally a simple data bag (readonly class) with no methods.
 * The FiberManager owns these and is the only thing that modifies their state.
 *
 * @internal
 */
final class FiberEntry
{
    public readonly float $createdAt;

    public function __construct(
        public readonly int      $curlHandleId,
        public readonly \Fiber   $fiber,
        public readonly Deferred $deferred,
        public readonly Request  $request,
        public readonly float    $timeoutAt, // microtime(true) value, 0 = no timeout
    ) {
        $this->createdAt = microtime(as_float: true);
    }

    /**
     * Returns the elapsed seconds since this entry was created.
     */
    public function elapsedSeconds(): float
    {
        return microtime(as_float: true) - $this->createdAt;
    }

    /**
     * Returns true if this entry has exceeded its configured timeout threshold.
     */
    public function isTimedOut(): bool
    {
        return $this->timeoutAt > 0.0 && microtime(as_float: true) >= $this->timeoutAt;
    }
}
