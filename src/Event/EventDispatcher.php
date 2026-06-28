<?php

declare(strict_types=1);

namespace WireHttp\Event;

/**
 * EventDispatcher — Zero-Allocation PSR-14-Inspired Event Dispatcher
 *
 * The EventDispatcher accepts events and routes them to all registered
 * listeners via the ListenerProvider. It is designed for maximum speed:
 * no dynamic dispatch overhead, no reflection, no container lookups.
 *
 * PSR-14 Compatibility:
 * ---------------------
 * While this class does not formally implement Psr\EventDispatcher\EventDispatcherInterface
 * (WireHTTP has zero dependencies), it follows PSR-14 semantics exactly:
 *   - Events are dispatched to listeners in priority order.
 *   - If an event is "stoppable" and propagation is stopped, no further
 *     listeners receive the event.
 *   - The dispatcher returns the event object after all listeners have run.
 *
 * Stoppable Events:
 * -----------------
 * Any event can implement `StoppableEventInterface` to gain propagation control.
 * Once a listener calls `$event->stopPropagation()`, subsequent listeners
 * are skipped. This is useful for "veto" patterns where one listener can
 * prevent further processing.
 *
 * Error Handling:
 * ---------------
 * By default, exceptions thrown by listeners bubble up to the caller.
 * You can register a global error handler via `onListenerError()` to
 * catch and log listener exceptions without crashing the request pipeline.
 *
 * Usage:
 *   $dispatcher = new EventDispatcher($listenerProvider);
 *   $dispatcher->dispatch(new RequestSentEvent($request));
 *
 *   // With error tolerance:
 *   $dispatcher->onListenerError(fn(\Throwable $e, $event) => logger()->error($e));
 *   $dispatcher->dispatch(new RequestSentEvent($request)); // bad listeners won't crash you
 */
final class EventDispatcher
{
    private readonly ListenerProvider $provider;

    /**
     * Optional error handler for listener exceptions.
     * Signature: callable(\Throwable, WireEventInterface): void
     */
    private ?\Closure $errorHandler = null;

    public function __construct(?ListenerProvider $provider = null)
    {
        $this->provider = $provider ?? new ListenerProvider();
    }

    // ─── Core Dispatch ────────────────────────────────────────────────────────

    /**
     * Dispatches an event to all registered listeners.
     *
     * @template T of WireEventInterface
     * @param T $event The event to dispatch.
     * @return T The event (possibly modified by listeners).
     */
    public function dispatch(WireEventInterface $event): WireEventInterface
    {
        $listeners = $this->provider->getListenersForEvent($event);

        foreach ($listeners as $listener) {
            // Check for propagation stop BEFORE calling the next listener
            if ($event instanceof StoppableEventTrait && $event->isPropagationStopped()) {
                break;
            }

            try {
                $listener($event);
            } catch (\Throwable $e) {
                if ($this->errorHandler !== null) {
                    ($this->errorHandler)($e, $event);
                } else {
                    throw $e;
                }
            }
        }

        return $event;
    }

    // ─── Listener Registration Shortcuts ─────────────────────────────────────

    /**
     * Convenience shortcut to register a listener without accessing the provider directly.
     *
     * @template T of WireEventInterface
     * @param class-string<T> $eventClass
     * @param callable        $listener
     * @param int             $priority
     */
    public function listen(string $eventClass, callable $listener, int $priority = 0): static
    {
        $this->provider->listen($eventClass, $listener, $priority);

        return $this;
    }

    /**
     * Registers a one-shot listener (fires once, then unregisters itself).
     *
     * @template T of WireEventInterface
     * @param class-string<T> $eventClass
     */
    public function listenOnce(string $eventClass, callable $listener, int $priority = 0): static
    {
        $this->provider->listenOnce($eventClass, $listener, $priority);

        return $this;
    }

    /**
     * Registers a global error handler for listener exceptions.
     * Signature: callable(\Throwable $e, WireEventInterface $event): void
     */
    public function onListenerError(\Closure $handler): static
    {
        $this->errorHandler = $handler;

        return $this;
    }

    /**
     * Returns the underlying ListenerProvider for advanced usage.
     */
    public function getProvider(): ListenerProvider
    {
        return $this->provider;
    }

    /**
     * Returns true if any listeners are registered for the given event class.
     *
     * @param class-string $eventClass
     */
    public function hasListeners(string $eventClass): bool
    {
        return $this->provider->hasListeners($eventClass);
    }
}

/**
 * StoppableEventTrait — Mixin for Events That Support Propagation Control
 *
 * Mix this trait into any event class to enable `stopPropagation()`.
 *
 * Usage:
 *   final class RequestSentEvent implements WireEventInterface {
 *       use StoppableEventTrait;
 *       // ...
 *   }
 */
trait StoppableEventTrait
{
    private bool $propagationStopped = false;

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }
}
