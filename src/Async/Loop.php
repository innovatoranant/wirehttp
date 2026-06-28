<?php

declare(strict_types=1);

namespace WireHttp\Async;

use WireHttp\Exceptions\NetworkException;

/**
 * Loop — The WireHTTP Native Fiber Event Loop
 *
 * The Loop is the central engine of WireHTTP's asynchronous architecture.
 * It is a singleton that coordinates the execution of multiple concurrent
 * in-flight HTTP requests using PHP 8.1 Fibers and `curl_multi_*` functions.
 *
 * Core Concept:
 * -------------
 * Traditional synchronous HTTP (Guzzle sync):
 *   [Request 1] → [WAIT 200ms] → [Response 1]
 *   [Request 2] → [WAIT 150ms] → [Response 2]
 *   Total: 350ms
 *
 * WireHTTP's Fiber-based async:
 *   [Request 1] ──────────────────────────────── [Response 1] ← 200ms
 *   [Request 2] ──────────────────── [Response 2]             ← 150ms
 *   Total: ~200ms (limited by the slowest request, not the sum)
 *
 * The Loop achieves this by:
 *   1. Accepting cURL multi handles from the Transport layer.
 *   2. Running `curl_multi_exec()` on every tick to check for completed requests.
 *   3. When a response arrives, finding the associated Fiber via FiberManager.
 *   4. Resolving the Deferred, which automatically resumes the waiting Fiber.
 *   5. Checking for timed-out requests via TimeoutWatcher.
 *   6. Repeating until all registered requests are complete.
 *
 * Integration with curl_multi:
 * ----------------------------
 * The key performance insight is that `curl_multi_exec()` is NON-BLOCKING.
 * It immediately returns the current state of all cURL handles without waiting.
 * We then use `curl_multi_select()` with a VERY SHORT timeout to yield CPU
 * to the OS between ticks, avoiding a tight busy-loop that would peg CPU at 100%.
 *
 * Integration with Fibers:
 * ------------------------
 * When a Fiber calls `$future->get()`:
 *   1. The Fiber suspends itself with `Fiber::suspend($this)` (see Future::get()).
 *   2. The Future's `$waitingFiber` property is set to that Fiber.
 *   3. The Loop continues ticking.
 *   4. When the cURL response arrives, the Deferred is resolved.
 *   5. Future::resolve() calls `$this->waitingFiber->resume($value)`.
 *   6. The Fiber resumes right after `Fiber::suspend()`, i.e., `$future->get()` returns.
 *
 * Singleton Pattern:
 * ------------------
 * The Loop is a singleton because there should only ever be ONE event loop per
 * PHP process. Multiple loops would fight over the curl_multi handle and create
 * race conditions.
 *
 * Usage:
 *   $loop = Loop::getInstance();
 *   $loop->addHandle($curlMultiHandle, $curlHandle, $deferred, $request, $timeout);
 *   $loop->run();
 *
 * Or, for a single-future context:
 *   $loop->runUntilSettled($future);
 */
final class Loop
{
    /**
     * Singleton instance.
     */
    private static ?Loop $instance = null;

    /**
     * The `curl_multi` resource handle.
     * All concurrent cURL handles are added to and managed through this.
     *
     * @var \CurlMultiHandle|null
     */
    private ?\CurlMultiHandle $multiHandle = null;

    /**
     * Tracks all in-flight Fiber-based requests.
     */
    private readonly FiberManager $fiberManager;

    /**
     * Tracks all timeout deadlines.
     */
    private readonly TimeoutWatcher $timeoutWatcher;

    /**
     * Map from spl_object_id(\CurlHandle) → \CurlHandle.
     * Needed because curl_multi_info_read() returns the CurlHandle but we need
     * its ID to look up the FiberEntry. We keep a reference here to prevent GC.
     *
     * @var array<int, \CurlHandle>
     */
    private array $curlHandles = [];

    /**
     * True while the Loop is actively running (inside `run()` or `runUntilSettled()`).
     * Used to prevent recursive loop invocations.
     */
    private bool $running = false;

    /**
     * Total ticks executed since the Loop was created.
     * Useful for diagnostics and testing.
     */
    private int $totalTicks = 0;

    /**
     * Maximum time in seconds for a single `curl_multi_select()` wait.
     * Lower = more responsive to timeouts. Higher = less CPU usage.
     * We cap this dynamically based on the earliest timeout deadline.
     */
    private const MAX_SELECT_TIMEOUT = 0.01; // 10ms maximum wait

    private function __construct()
    {
        $this->fiberManager   = new FiberManager();
        $this->timeoutWatcher = new TimeoutWatcher();
        $this->multiHandle    = curl_multi_init();

        if ($this->multiHandle === false) {
            throw new \RuntimeException('Failed to initialize curl_multi handle. Check that ext-curl is loaded.');
        }

        // Enable HTTP/2 multiplexing and pipelining for maximum throughput
        curl_multi_setopt($this->multiHandle, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
    }

    /**
     * Returns the singleton Loop instance.
     */
    public static function getInstance(): static
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    // ─── Adding Requests ──────────────────────────────────────────────────────

    /**
     * Adds a new cURL handle to the multi handle and registers it in the FiberManager.
     * This is how the CurlMultiHandler registers async requests with the Loop.
     *
     * @param \CurlHandle $curlHandle     The initialized cURL handle ready to execute.
     * @param Deferred    $deferred       The Deferred to resolve when the response arrives.
     * @param \WireHttp\Http\Request $request The originating HTTP request.
     * @param float       $timeoutSeconds The total timeout in seconds (0 = none).
     */
    public function addHandle(
        \CurlHandle             $curlHandle,
        Deferred                $deferred,
        \WireHttp\Http\Request  $request,
        float                   $timeoutSeconds = 0.0,
    ): void {
        $handleId = spl_object_id($curlHandle);

        // Keep a reference to prevent garbage collection of the handle
        $this->curlHandles[$handleId] = $curlHandle;

        // Add to the curl_multi queue
        $result = curl_multi_add_handle($this->multiHandle, $curlHandle);

        if ($result !== CURLM_OK) {
            throw new \RuntimeException(
                sprintf('Failed to add cURL handle to multi: error code %d.', $result)
            );
        }

        // Create a Fiber that will suspend itself and yield when curl_multi finishes
        $fiber = new \Fiber(function () use ($curlHandle, $deferred): void {
            // The Fiber's job is simply to suspend itself.
            // The Loop will resume it with the response data when ready.
            \Fiber::suspend();
        });

        // Register with FiberManager
        $deadline = $timeoutSeconds > 0.0 ? microtime(as_float: true) + $timeoutSeconds : 0.0;

        $this->fiberManager->register(
            curlHandleId: $handleId,
            fiber: $fiber,
            deferred: $deferred,
            request: $request,
            timeoutAt: $deadline,
        );

        // Register with TimeoutWatcher
        if ($timeoutSeconds > 0.0) {
            $this->timeoutWatcher->watch($handleId, $timeoutSeconds);
        }

        // Start the fiber (it will immediately suspend)
        $fiber->start();
    }

    // ─── Running the Loop ─────────────────────────────────────────────────────

    /**
     * Runs the event loop until all currently registered async requests complete.
     * This is the blocking entry point for running all pending requests to completion.
     *
     * Calling this from a Fiber is safe — it will not deadlock because the Loop
     * processes curl_multi events rather than waiting on a specific Fiber.
     */
    public function run(): void
    {
        if ($this->running) {
            return; // Already running — don't nest
        }

        $this->running = true;

        try {
            while (!$this->fiberManager->isEmpty()) {
                $this->tick();
            }
        } finally {
            $this->running = false;
        }
    }

    /**
     * Runs the event loop until a SPECIFIC Future has settled (resolved or rejected).
     *
     * This is used by `Future::get()` when called outside of a Fiber context.
     * Other in-flight requests continue to be processed during this wait.
     *
     * @param Future<mixed> $future The Future to wait for.
     */
    public function runUntilSettled(Future $future): void
    {
        if ($this->running) {
            // We are already in a Loop::run() call. The existing tick() cycle
            // will eventually settle this future. We just need to yield back.
            // This is a no-op here — the outer run() loop will handle it.
            return;
        }

        $this->running = true;

        try {
            while ($future->isPending() && !$this->fiberManager->isEmpty()) {
                $this->tick();
            }
        } finally {
            $this->running = false;
        }
    }

    /**
     * Executes a single tick of the event loop.
     *
     * One tick consists of:
     *   1. Drive curl_multi to check for completed requests (non-blocking).
     *   2. Process any completed cURL handles and resolve/reject their Deferreds.
     *   3. Check for timed-out requests via TimeoutWatcher.
     *   4. Use curl_multi_select() to yield CPU to the OS briefly (prevents busy-loop).
     */
    public function tick(): void
    {
        $this->totalTicks++;

        // ── Step 1: Drive curl_multi (NON-BLOCKING) ──────────────────────────
        $stillRunning = 0;
        $execResult   = CURLM_CALL_MULTI_PERFORM;

        while ($execResult === CURLM_CALL_MULTI_PERFORM) {
            $execResult = curl_multi_exec($this->multiHandle, $stillRunning);
        }

        // ── Step 2: Process completed handles ───────────────────────────────
        $this->processCompletedHandles();

        // ── Step 3: Check for timeouts ───────────────────────────────────────
        $this->processTimeouts();

        // ── Step 4: Yield to OS briefly (unless all done) ───────────────────
        if (!$this->fiberManager->isEmpty()) {
            // Calculate the optimal wait: don't wait longer than the next timeout
            $secondsUntilTimeout = $this->timeoutWatcher->secondsUntilEarliestDeadline();
            $selectTimeout       = min(self::MAX_SELECT_TIMEOUT, $secondsUntilTimeout);

            // curl_multi_select() blocks for AT MOST $selectTimeout seconds.
            // If data arrives sooner, it returns immediately. This is the key
            // to both responsiveness AND not burning 100% CPU in a busy loop.
            if ($selectTimeout > 0.0) {
                curl_multi_select($this->multiHandle, $selectTimeout);
            }
        }
    }

    // ─── Introspection ────────────────────────────────────────────────────────

    /**
     * Returns true if the Loop is actively executing (inside `run()` or `runUntilSettled()`).
     */
    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Returns the number of requests currently in-flight.
     */
    public function pendingCount(): int
    {
        return $this->fiberManager->pendingCount();
    }

    /**
     * Returns true if there are no in-flight requests.
     */
    public function isEmpty(): bool
    {
        return $this->fiberManager->isEmpty();
    }

    /**
     * Returns total tick count since the Loop was created.
     * Useful for performance analysis.
     */
    public function getTotalTicks(): int
    {
        return $this->totalTicks;
    }

    /**
     * Returns a snapshot of diagnostic statistics.
     *
     * @return array{running: bool, pending: int, total_ticks: int, fiber_stats: array}
     */
    public function getStats(): array
    {
        return [
            'running'     => $this->running,
            'pending'     => $this->fiberManager->pendingCount(),
            'total_ticks' => $this->totalTicks,
            'fiber_stats' => $this->fiberManager->getStats(),
        ];
    }

    // ─── Private Helpers ─────────────────────────────────────────────────────

    /**
     * Reads all completed cURL handles from the multi queue and dispatches
     * their results to the appropriate Deferred via the FiberManager.
     */
    private function processCompletedHandles(): void
    {
        while ($info = curl_multi_info_read($this->multiHandle)) {
            if ($info['msg'] !== CURLMSG_DONE) {
                continue;
            }

            $curlHandle = $info['handle'];
            $handleId   = spl_object_id($curlHandle);
            $curlResult = $info['result'];

            // Remove from curl_multi so it can be cleaned up
            curl_multi_remove_handle($this->multiHandle, $curlHandle);

            // Unwatch timeout (request completed before deadline)
            $this->timeoutWatcher->unwatch($handleId);

            $entry = $this->fiberManager->getByHandleId($handleId);

            if ($entry === null) {
                // Orphaned handle — clean up and continue
                curl_close($curlHandle);
                unset($this->curlHandles[$handleId]);

                continue;
            }

            if ($curlResult !== CURLE_OK) {
                // cURL transport error — reject the Future
                $exception = NetworkException::fromCurlHandle($curlHandle, $entry->request);
                $this->fiberManager->reject($handleId, $exception);
            } else {
                // Success — the transport layer reads the response from the handle.
                // We signal the FiberManager with the raw handle; the Deferred
                // resolution happens via the CurlMultiHandler which registered a
                // response-builder callback in the entry.
                // For the Loop itself, we emit the raw CURL info and let the
                // registered response extractor (set in addHandle from Transport layer)
                // build the Response object.
                //
                // However, at the Loop level, we notify the FiberManager that
                // this handle is "done" — the Transport layer's response extractor
                // has already been invoked by the time tick() processes this.
                $responseData = [
                    'handle' => $curlHandle,
                    'info'   => curl_getinfo($curlHandle),
                ];

                // The FiberManager resolves the Deferred with the raw handle info.
                // The Transport layer's CurlMultiHandler post-processes this into a Response.
                $this->fiberManager->resolve($handleId, $responseData);
            }

            // Clean up the cURL handle
            if (!isset($this->curlHandles[$handleId])) {
                curl_close($curlHandle);
            }

            unset($this->curlHandles[$handleId]);
        }
    }

    /**
     * Checks the TimeoutWatcher for expired entries and rejects them.
     */
    private function processTimeouts(): void
    {
        $expired = $this->timeoutWatcher->checkExpired();

        foreach ($expired as $timeoutEntry) {
            $handleId = $timeoutEntry->curlHandleId;

            if (isset($this->curlHandles[$handleId])) {
                curl_multi_remove_handle($this->multiHandle, $this->curlHandles[$handleId]);
                curl_close($this->curlHandles[$handleId]);
                unset($this->curlHandles[$handleId]);
            }

            $fiberEntry = $this->fiberManager->getByHandleId($handleId);

            if ($fiberEntry === null) {
                continue;
            }

            $this->fiberManager->reject($handleId, $timeoutEntry->toException());
        }
    }

    /**
     * Prevents cloning — Loop is a singleton.
     */
    private function __clone() {}

    /**
     * Gracefully shuts down the Loop on destruction.
     */
    public function __destruct()
    {
        // Reject all pending futures so waiting Fibers don't hang forever
        $this->fiberManager->rejectAll(
            new \RuntimeException('WireHTTP Loop was destroyed while requests were still in flight.')
        );

        if ($this->multiHandle !== null) {
            curl_multi_close($this->multiHandle);
            $this->multiHandle = null;
        }
    }
}
