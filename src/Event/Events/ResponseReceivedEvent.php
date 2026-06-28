<?php

declare(strict_types=1);

namespace WireHttp\Event\Events;

use WireHttp\Event\WireEventInterface;
use WireHttp\Http\Request;
use WireHttp\Http\Response;

/**
 * ResponseReceivedEvent — Fired AFTER a response is received from the transport.
 *
 * This event fires after the transport has completed but BEFORE the response
 * is returned to the caller. It carries both the original Request and the
 * received Response, plus the total elapsed time in seconds.
 *
 * Common uses:
 *  - Logging: log the response status, duration, and body size.
 *  - Metrics: record request duration, error rates, etc.
 *  - Caching: store the response in a cache for future use.
 *  - APM (Application Performance Monitoring): trace request lifecycle.
 *  - Alerting: detect and react to high error rates.
 */
final class ResponseReceivedEvent implements WireEventInterface
{
    private readonly float $createdAt;
    private readonly float $durationSeconds;

    public function __construct(
        private readonly Request  $request,
        private readonly Response $response,
        float $startedAt,
    ) {
        $this->createdAt       = microtime(as_float: true);
        $this->durationSeconds = $this->createdAt - $startedAt;
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function getResponse(): Response
    {
        return $this->response;
    }

    /**
     * Returns the total time from request dispatch to response receipt, in seconds.
     */
    public function getDurationSeconds(): float
    {
        return $this->durationSeconds;
    }

    /**
     * Returns the duration formatted as milliseconds (for logging).
     */
    public function getDurationMs(): float
    {
        return round($this->durationSeconds * 1000, 2);
    }

    /**
     * Returns true if the response indicates success (2xx).
     */
    public function isSuccess(): bool
    {
        return $this->response->isSuccessful();
    }

    /**
     * Returns true if the response indicates a client or server error (4xx/5xx).
     */
    public function isError(): bool
    {
        return $this->response->isClientError() || $this->response->isServerError();
    }

    public function getCreatedAt(): float
    {
        return $this->createdAt;
    }
}
