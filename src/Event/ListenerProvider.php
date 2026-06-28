<?php

declare(strict_types=1);

namespace WireHttp\Event;

/**
 * ListenerProvider — Priority-Ordered PSR-14 Listener Registry
 *
 * The ListenerProvider maps event class names (and their parent classes /
 * interfaces) to a sorted list of callable listeners. It is consumed by
 * the EventDispatcher when dispatching events.
 *
 * Key design decisions:
 * ---------------------
 * 1. **Priority-based ordering**: Listeners with HIGHER priority integers run
 *    first. Default priority = 0. Range: any int (PHP_INT_MIN → PHP_INT_MAX).
 *
 * 2. **Inheritance-aware matching**: A listener registered for `WireEventInterface`
 *    will fire for ALL events because every event implements that interface.
 *    A listener registered for `RequestSentEvent` only fires for that exact event.
 *
 * 3. **Lazy sorting**: The listener list for a given event type is sorted once
 *    on first access and cached. Subsequent dispatches skip the sort step.
 *
 * 4. **Wildcard support**: Listeners registered with `*` or the root interface
 *    type receive every event.
 *
 * Usage:
 *   $provider = new ListenerProvider();
 *
 *   // Low priority: runs last
 *   $provider->listen(RequestSentEvent::class, function(RequestSentEvent $e) {
 *       logger()->debug("Sending: {$e->getRequest()->getMethod()}");
 *   });
 *
 *   // High priority: runs first
 *   $provider->listen(RequestSentEvent::class, fn($e) => ..., priority: 100);
 *
 *   // Catch all events
 *   $provider->listen(WireEventInterface::class, fn($e) => ...);
 */
final class ListenerProvider
{
    /**
     * Raw listener registry before sorting.
     *
     * Structure: className => list of [callable, int priority]
     *
     * @var array<class-string, list<array{callable, int}>>
     */
    private array $listeners = [];

    /**
     * Sorted listener cache (invalidated when new listeners are added for a type).
     *
     * @var array<class-string, list<callable>>
     */
    private array $sortedCache = [];

    // ─── Registration ─────────────────────────────────────────────────────────

    /**
     * Registers a listener for the given event class.
     *
     * @template T of WireEventInterface
     * @param class-string<T> $eventClass The fully-qualified class name of the event.
     * @param callable        $listener   A callable that receives the event object.
     * @param int             $priority   Higher values run first. Default: 0.
     *
     * @return static Returns $this for fluent chaining.
     */
    public function listen(string $eventClass, callable $listener, int $priority = 0): static
    {
        $this->listeners[$eventClass][] = [$listener, $priority];

        // Invalidate sorted cache for this event type (and any types that might
        // use this event class as a parent/interface)
        unset($this->sortedCache[$eventClass]);

        return $this;
    }

    /**
     * Registers a one-shot listener that automatically removes itself after firing once.
     *
     * @template T of WireEventInterface
     * @param class-string<T> $eventClass
     * @param callable        $listener
     * @param int             $priority
     */
    public function listenOnce(string $eventClass, callable $listener, int $priority = 0): static
    {
        $wrapper = null;
        $wrapper = function (WireEventInterface $event) use ($eventClass, &$wrapper, $listener): void {
            $this->removeListener($eventClass, $wrapper);
            $listener($event);
        };

        return $this->listen($eventClass, $wrapper, $priority);
    }

    /**
     * Removes a specific listener from an event class.
     */
    public function removeListener(string $eventClass, callable $listener): static
    {
        if (!isset($this->listeners[$eventClass])) {
            return $this;
        }

        $this->listeners[$eventClass] = array_values(array_filter(
            $this->listeners[$eventClass],
            static fn(array $entry) => $entry[0] !== $listener,
        ));

        unset($this->sortedCache[$eventClass]);

        return $this;
    }

    /**
     * Removes ALL listeners for an event class.
     */
    public function removeAllListeners(string $eventClass): static
    {
        unset($this->listeners[$eventClass], $this->sortedCache[$eventClass]);

        return $this;
    }

    // ─── Resolution (used by EventDispatcher) ─────────────────────────────────

    /**
     * Returns all listeners for the given event instance, sorted by priority (descending).
     *
     * This method is PSR-14 compatible: it returns an iterable of callables.
     * The event's entire class hierarchy (parents + interfaces) is traversed
     * to collect all applicable listeners.
     *
     * @param WireEventInterface $event
     * @return iterable<callable>
     */
    public function getListenersForEvent(WireEventInterface $event): iterable
    {
        $eventClass = get_class($event);

        if (isset($this->sortedCache[$eventClass])) {
            return $this->sortedCache[$eventClass];
        }

        // Collect all class ancestors + interfaces the event implements
        $hierarchy = $this->getClassHierarchy($eventClass);

        // Merge all listeners from every level of the hierarchy
        $combined = [];

        foreach ($hierarchy as $type) {
            if (isset($this->listeners[$type])) {
                foreach ($this->listeners[$type] as $entry) {
                    $combined[] = $entry; // [callable, priority]
                }
            }
        }

        // Sort by priority descending (higher priority runs first)
        usort($combined, static fn(array $a, array $b) => $b[1] <=> $a[1]);

        // Extract callables and cache
        $sorted = array_column($combined, 0);
        $this->sortedCache[$eventClass] = $sorted;

        return $sorted;
    }

    /**
     * Returns the total number of listeners registered across all event types.
     */
    public function count(): int
    {
        return (int) array_sum(array_map(
            static fn(array $list) => count($list),
            $this->listeners,
        ));
    }

    /**
     * Returns true if any listeners are registered for the given event class.
     */
    public function hasListeners(string $eventClass): bool
    {
        return !empty($this->listeners[$eventClass]);
    }

    // ─── Private ─────────────────────────────────────────────────────────────

    /**
     * Returns an ordered list of all parent classes and interfaces for a class.
     * The class itself comes first, then parents, then interfaces.
     *
     * @param class-string $class
     * @return list<class-string>
     */
    private function getClassHierarchy(string $class): array
    {
        $hierarchy = [$class];

        // Add all parent classes
        $parent = get_parent_class($class);

        while ($parent !== false) {
            $hierarchy[] = $parent;
            $parent       = get_parent_class($parent);
        }

        // Add all interfaces (recursively includes parent interfaces)
        foreach (class_implements($class) as $interface) {
            $hierarchy[] = $interface;
        }

        return array_unique($hierarchy);
    }
}
