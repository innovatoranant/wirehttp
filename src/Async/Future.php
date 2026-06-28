<?php

declare(strict_types=1);

namespace WireHttp\Async;

use WireHttp\Http\Response;

/**
 * Future<T> — The Async Return Value (Our Promise Replacement)
 *
 * A Future represents a value that does not exist yet but WILL exist at some
 * point in the future — specifically, when the HTTP response arrives from the server.
 *
 * This is WireHTTP's replacement for Guzzle's `GuzzleHttp\Promise\PromiseInterface`.
 *
 * How it differs from Guzzle Promises:
 * -------------------------------------
 * Guzzle promises use a `.then(fn)->wait()` style that is essentially a manually
 * managed coroutine system implemented in userland PHP. When you call `wait()`,
 * Guzzle hijacks the current PHP process and runs its internal event loop until
 * the promise settles. This blocks the entire script.
 *
 * WireHTTP Futures work with PHP 8.1 Fibers:
 *  - Calling `->get()` from within a running Fiber SUSPENDS the Fiber (not the process).
 *  - The Loop's `tick()` continues running, processing other requests and Fibers.
 *  - When the response arrives, the Fiber is RESUMED and `->get()` returns the value.
 *  - From the developer's perspective, it reads like synchronous code, but is async!
 *
 * States:
 * -------
 *  - PENDING:  The HTTP request has been sent; no response yet.
 *  - RESOLVED: The HTTP response arrived successfully. `->get()` returns the Response.
 *  - REJECTED: The request failed (network error, timeout, etc.). `->get()` throws.
 *
 * Usage:
 *   $future = Wire::get('https://api.example.com/users')->sendAsync();
 *
 *   // Option 1: Suspend current Fiber and wait (reads like sync code!)
 *   $response = $future->get(); // Suspends until response arrives
 *
 *   // Option 2: Callbacks (for non-Fiber contexts)
 *   $future->then(fn(Response $r) => print $r->json())
 *          ->catch(fn(\Throwable $e) => print $e->getMessage());
 *
 *   // Option 3: Await all concurrently
 *   [$users, $posts] = Future::all(
 *       Wire::get('/api/users')->sendAsync(),
 *       Wire::get('/api/posts')->sendAsync(),
 *   );
 *
 * @template T
 */
final class Future
{
    private const STATE_PENDING  = 'pending';
    private const STATE_RESOLVED = 'resolved';
    private const STATE_REJECTED = 'rejected';

    private string $state = self::STATE_PENDING;

    /** @var T|null */
    private mixed $value = null;

    private ?\Throwable $reason = null;

    /**
     * Callbacks to invoke when this Future resolves successfully.
     * @var list<\Closure>
     */
    private array $onResolved = [];

    /**
     * Callbacks to invoke when this Future is rejected.
     * @var list<\Closure>
     */
    private array $onRejected = [];

    /**
     * Callbacks to always invoke regardless of outcome (like finally).
     * @var list<\Closure>
     */
    private array $onSettled = [];

    /**
     * The Fiber that is currently suspended waiting for this Future.
     * Set by `->get()` when called from within a Fiber.
     */
    private ?\Fiber $waitingFiber = null;

    /**
     * The unique ID of this Future (for FiberManager tracking).
     */
    private readonly string $id;

    /**
     * Timestamp (as float microseconds) when this Future was created.
     * Used by TimeoutWatcher.
     */
    private readonly float $createdAt;

    public function __construct()
    {
        $this->id        = uniqid('future_', more_entropy: true);
        $this->createdAt = microtime(as_float: true);
    }

    // ─── State Checks ─────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    public function isResolved(): bool
    {
        return $this->state === self::STATE_RESOLVED;
    }

    public function isRejected(): bool
    {
        return $this->state === self::STATE_REJECTED;
    }

    public function isSettled(): bool
    {
        return $this->state !== self::STATE_PENDING;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCreatedAt(): float
    {
        return $this->createdAt;
    }

    // ─── Primary API ──────────────────────────────────────────────────────────

    /**
     * Blocks (or suspends the current Fiber) until this Future settles.
     *
     * Behavior depends on context:
     *
     * A) Called from WITHIN a Fiber:
     *    The Fiber is suspended via `Fiber::suspend()`. The event Loop continues
     *    running and processes other requests. When this Future resolves, the Loop
     *    resumes this Fiber and `->get()` returns the value (or throws the reason).
     *
     * B) Called from OUTSIDE a Fiber (main script context):
     *    We synchronously run the event loop (using Loop::run()) until this
     *    Future settles. This is the blocking equivalent of Guzzle's `wait()`.
     *
     * @return T
     * @throws \Throwable if the Future was rejected.
     */
    public function get(): mixed
    {
        if ($this->isResolved()) {
            return $this->value;
        }

        if ($this->isRejected()) {
            throw $this->reason;
        }

        // If we're inside a running Fiber, suspend it.
        // The Loop will resume this Fiber once the Future settles.
        if (\Fiber::getCurrent() !== null) {
            $this->waitingFiber = \Fiber::getCurrent();
            \Fiber::suspend($this); // Suspend — pass ourselves to the Loop

            // When resumed, the state will have changed
            if ($this->isRejected()) {
                throw $this->reason;
            }

            return $this->value;
        }

        // Not in a Fiber — run the loop synchronously until settled
        Loop::getInstance()->runUntilSettled($this);

        if ($this->isRejected()) {
            throw $this->reason;
        }

        return $this->value;
    }

    /**
     * Maps the settled value of this Future to a new Future using a closure.
     * If the closure throws, the mapped Future is rejected.
     *
     * @template U
     * @param \Closure(T): U $mapper
     * @return Future<U>
     */
    public function map(\Closure $mapper): Future
    {
        $mappedFuture = new self();

        $this->then(static function (mixed $value) use ($mappedFuture, $mapper): void {
            try {
                $mappedValue = $mapper($value);
                $mappedFuture->resolve($mappedValue);
            } catch (\Throwable $e) {
                $mappedFuture->reject($e);
            }
        });

        $this->catch(static function (\Throwable $e) use ($mappedFuture): void {
            $mappedFuture->reject($e);
        });

        return $mappedFuture;
    }

    /**
     * Registers a callback to be called when this Future resolves successfully.
     * The callback receives the resolved value as its argument.
     * Returns $this for fluent chaining.
     *
     * @param \Closure(T): void $callback
     */
    public function then(\Closure $callback): static
    {
        if ($this->isResolved()) {
            $callback($this->value);

            return $this;
        }

        if (!$this->isRejected()) {
            $this->onResolved[] = $callback;
        }

        return $this;
    }

    /**
     * Registers a callback for when this Future is rejected (an exception occurred).
     * Returns $this for fluent chaining.
     *
     * @param \Closure(\Throwable): void $callback
     */
    public function catch(\Closure $callback): static
    {
        if ($this->isRejected()) {
            $callback($this->reason);

            return $this;
        }

        if (!$this->isResolved()) {
            $this->onRejected[] = $callback;
        }

        return $this;
    }

    /**
     * Registers a callback that runs regardless of resolution or rejection (like `finally`).
     * The callback receives no arguments.
     * Returns $this for fluent chaining.
     *
     * @param \Closure(): void $callback
     */
    public function finally(\Closure $callback): static
    {
        if ($this->isSettled()) {
            $callback();

            return $this;
        }

        $this->onSettled[] = $callback;

        return $this;
    }

    /**
     * Returns the Fiber that is suspended waiting for this Future, if any.
     * Used internally by the Loop to resume the right Fiber when resolving.
     */
    public function getWaitingFiber(): ?\Fiber
    {
        return $this->waitingFiber;
    }

    // ─── Resolution (called by Deferred, not by external code) ───────────────

    /**
     * @internal Called by Deferred::resolve() only.
     * @param T $value
     */
    public function resolve(mixed $value): void
    {
        if (!$this->isPending()) {
            throw new \LogicException(
                sprintf('Cannot resolve a Future that is already in state "%s".', $this->state)
            );
        }

        $this->value = $value;
        $this->state = self::STATE_RESOLVED;

        // Resume the waiting Fiber if one is suspended
        if ($this->waitingFiber !== null && !$this->waitingFiber->isTerminated()) {
            $this->waitingFiber->resume($value);
            $this->waitingFiber = null;
        }

        // Fire then() callbacks
        foreach ($this->onResolved as $callback) {
            $callback($value);
        }

        // Fire finally() callbacks
        foreach ($this->onSettled as $callback) {
            $callback();
        }

        $this->onResolved = [];
        $this->onSettled  = [];
    }

    /**
     * @internal Called by Deferred::reject() only.
     */
    public function reject(\Throwable $reason): void
    {
        if (!$this->isPending()) {
            throw new \LogicException(
                sprintf('Cannot reject a Future that is already in state "%s".', $this->state)
            );
        }

        $this->reason = $reason;
        $this->state  = self::STATE_REJECTED;

        // Resume the waiting Fiber so it can rethrow
        if ($this->waitingFiber !== null && !$this->waitingFiber->isTerminated()) {
            $this->waitingFiber->throw($reason);
            $this->waitingFiber = null;
        }

        // Fire catch() callbacks
        foreach ($this->onRejected as $callback) {
            $callback($reason);
        }

        // Fire finally() callbacks
        foreach ($this->onSettled as $callback) {
            $callback();
        }

        $this->onRejected = [];
        $this->onSettled  = [];
    }

    // ─── Static Combinators ───────────────────────────────────────────────────

    /**
     * Awaits all given Futures concurrently.
     * Suspends (or blocks) until ALL Futures have settled.
     * Throws immediately if ANY Future is rejected.
     *
     * @template V
     * @param Future<V> ...$futures
     * @return list<V>
     * @throws \Throwable if any Future is rejected.
     */
    public static function all(self ...$futures): array
    {
        $results = [];

        foreach ($futures as $index => $future) {
            $results[$index] = $future->get();
        }

        return $results;
    }

    /**
     * Awaits all given Futures concurrently and returns all results/errors.
     * Unlike `all()`, this does NOT throw on rejection — it collects everything.
     *
     * Returns an array of ['status' => 'resolved'|'rejected', 'value'|'reason' => mixed].
     *
     * @param Future<mixed> ...$futures
     * @return list<array{status: string, value?: mixed, reason?: \Throwable}>
     */
    public static function allSettled(self ...$futures): array
    {
        $results = [];

        foreach ($futures as $future) {
            try {
                $results[] = ['status' => 'resolved', 'value' => $future->get()];
            } catch (\Throwable $e) {
                $results[] = ['status' => 'rejected', 'reason' => $e];
            }
        }

        return $results;
    }

    /**
     * Returns a new Future that resolves with $value immediately.
     * Useful for normalizing sync/async return values.
     *
     * @template V
     * @param V $value
     * @return Future<V>
     */
    public static function resolved(mixed $value): static
    {
        $future = new static();
        $future->resolve($value);

        return $future;
    }

    /**
     * Returns a new Future that is already rejected with the given reason.
     *
     * @return Future<never>
     */
    public static function rejected(\Throwable $reason): static
    {
        $future = new static();
        $future->reject($reason);

        return $future;
    }
}
