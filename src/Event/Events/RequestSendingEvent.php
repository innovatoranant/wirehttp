<?php

declare(strict_types=1);

namespace WireHttp\Event\Events;

use WireHttp\Event\StoppableEventTrait;
use WireHttp\Event\WireEventInterface;
use WireHttp\Http\Request;

/**
 * RequestSendingEvent — Fired BEFORE a request is dispatched to the transport.
 *
 * This event is fired by the MiddlewareStack's innermost handler (just before
 * calling `$transport->send()`). Listeners can observe or modify the request.
 *
 * Stoppable: YES — a listener can call `stopPropagation()` to short-circuit
 * and return a synthetic response. Useful for caching layers.
 *
 * Common uses:
 *  - Logging outgoing requests.
 *  - Injecting dynamic headers (e.g., dynamic auth tokens).
 *  - Caching: check cache before the request goes out.
 */
final class RequestSendingEvent implements WireEventInterface
{
    use StoppableEventTrait;

    private readonly float $createdAt;
    private Request $request;

    public function __construct(Request $request)
    {
        $this->request   = $request;
        $this->createdAt = microtime(as_float: true);
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * Allows middleware/listeners to replace the request before sending.
     */
    public function withRequest(Request $request): void
    {
        $this->request = $request;
    }

    public function getCreatedAt(): float
    {
        return $this->createdAt;
    }
}
