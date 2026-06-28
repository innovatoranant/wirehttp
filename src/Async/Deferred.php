<?php

declare(strict_types=1);

namespace WireHttp\Async;

/**
 * Deferred — The "Write Side" of a Future
 *
 * The Deferred is the internal mechanism the Transport layer uses to control
 * the lifecycle of a Future. It is intentionally NOT exposed to end-users of
 * the library — it is an internal implementation detail.
 *
 * Pattern:
 * --------
 * The Transport layer creates a Deferred before sending a request.
 * It gives the associated `Future` to the caller (e.g., RequestBuilder::sendAsync()).
 * When the cURL response arrives (detected by CurlMultiHandler and the Loop),
 * the Transport calls either `->resolve($response)` or `->reject($exception)`.
 *
 * This is equivalent to:
 *  - JavaScript's `new Promise((resolve, reject) => {...})` pattern.
 *  - ReactPHP's `Deferred` class.
 *  - Revolt's `DeferredFuture`.
 *
 * But unlike all of those, WireHTTP's Deferred is deeply integrated with the
 * native PHP Fiber system — resolving a Deferred automatically resumes any
 * Fiber that is suspended waiting for the associated Future.
 *
 * Usage (Transport layer only):
 *
 *   $deferred = new Deferred();
 *   $future   = $deferred->getFuture(); // Give this to the caller
 *
 *   // Later, when the response arrives...
 *   $deferred->resolve($response);   // or $deferred->reject($exception);
 *
 * @template T
 */
final class Deferred
{
    /** @var Future<T> */
    private readonly Future $future;

    public function __construct()
    {
        $this->future = new Future();
    }

    /**
     * Returns the Future associated with this Deferred.
     * This is what you give to the caller to await/observe the result.
     *
     * @return Future<T>
     */
    public function getFuture(): Future
    {
        return $this->future;
    }

    /**
     * Resolves the associated Future with a value.
     * This unblocks any Fiber suspended on `$future->get()`,
     * and fires all registered `->then()` callbacks.
     *
     * Can only be called once. Throws \LogicException if the Future is already settled.
     *
     * @param T $value
     * @throws \LogicException if the Future is already resolved or rejected.
     */
    public function resolve(mixed $value): void
    {
        $this->future->resolve($value);
    }

    /**
     * Rejects the associated Future with a Throwable reason.
     * This resumes any waiting Fiber with a thrown exception,
     * and fires all registered `->catch()` callbacks.
     *
     * Can only be called once. Throws \LogicException if the Future is already settled.
     *
     * @throws \LogicException if the Future is already resolved or rejected.
     */
    public function reject(\Throwable $reason): void
    {
        $this->future->reject($reason);
    }

    /**
     * Returns true if the Future has been settled (resolved or rejected).
     */
    public function isSettled(): bool
    {
        return $this->future->isSettled();
    }

    /**
     * Returns true if the Future is still pending.
     */
    public function isPending(): bool
    {
        return $this->future->isPending();
    }

    /**
     * Returns the Future's unique ID.
     * Used by FiberManager and Loop for tracking.
     */
    public function getId(): string
    {
        return $this->future->getId();
    }

    /**
     * Safely resolves the Future if it is still pending.
     * No-op (no exception) if already settled.
     * Useful in cleanup / finally blocks where double-settle is possible.
     *
     * @param T $value
     */
    public function tryResolve(mixed $value): bool
    {
        if ($this->future->isPending()) {
            $this->future->resolve($value);

            return true;
        }

        return false;
    }

    /**
     * Safely rejects the Future if it is still pending.
     * No-op (no exception) if already settled.
     */
    public function tryReject(\Throwable $reason): bool
    {
        if ($this->future->isPending()) {
            $this->future->reject($reason);

            return true;
        }

        return false;
    }
}
