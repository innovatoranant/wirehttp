<?php

declare(strict_types=1);

namespace WireHttp\Event\Events;

use WireHttp\Event\WireEventInterface;
use WireHttp\Http\Request;
use WireHttp\Http\Response;

/**
 * RequestRetryingEvent — Fired BEFORE each retry attempt.
 *
 * The RetryInterceptor fires this event before sleeping and re-sending a
 * failed request. Listeners can use this for logging, metrics (retry counters),
 * or alerting when services become unstable.
 *
 * Fields:
 *  - $request:     The request being retried.
 *  - $response:    The last response (if the failure was an HTTP error); null if it was a network exception.
 *  - $exception:   The last exception (if the failure was a network error); null if it was an HTTP error.
 *  - $attempt:     0-indexed attempt number. Attempt 0 = first retry, 1 = second retry, etc.
 *  - $delaySeconds: The calculated delay before the next attempt.
 */
final class RequestRetryingEvent implements WireEventInterface
{
    private readonly float $createdAt;

    public function __construct(
        private readonly Request     $request,
        private readonly ?Response   $response,
        private readonly ?\Throwable $exception,
        private readonly int         $attempt,
        private readonly float       $delaySeconds,
    ) {
        $this->createdAt = microtime(as_float: true);
    }

    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * Returns the last response received, or null if the failure was a network exception.
     */
    public function getResponse(): ?Response
    {
        return $this->response;
    }

    /**
     * Returns the thrown exception, or null if the failure was an HTTP error response.
     */
    public function getException(): ?\Throwable
    {
        return $this->exception;
    }

    /**
     * Returns the 0-indexed retry attempt number.
     * 0 = first retry (after the original attempt failed).
     */
    public function getAttempt(): int
    {
        return $this->attempt;
    }

    /**
     * Returns the delay in seconds before the retry request is sent.
     */
    public function getDelaySeconds(): float
    {
        return $this->delaySeconds;
    }

    /**
     * Returns whether the last failure was caused by a network-level exception
     * (as opposed to a 4xx/5xx HTTP response).
     */
    public function wasNetworkError(): bool
    {
        return $this->exception !== null;
    }

    public function getCreatedAt(): float
    {
        return $this->createdAt;
    }
}
