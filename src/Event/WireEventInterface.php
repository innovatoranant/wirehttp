<?php

declare(strict_types=1);

namespace WireHttp\Event;

use WireHttp\Http\Request;
use WireHttp\Http\Response;

/**
 * WireEventInterface — Base Marker for All WireHTTP Events
 *
 * All events emitted by WireHTTP implement this interface. Listeners can
 * type-hint against this interface to receive ALL events, or against a
 * specific subtype to receive only that category.
 *
 * Stoppable Events:
 * -----------------
 * Events implementing `StoppableEventInterface` can signal that propagation
 * should stop. The dispatcher MUST NOT pass the event to subsequent listeners
 * once `isPropagationStopped()` returns true.
 */
interface WireEventInterface
{
    /**
     * Returns the time (microtime float) when this event was created.
     */
    public function getCreatedAt(): float;
}
