<?php

declare(strict_types=1);

namespace WireHttp\Exceptions;

use WireHttp\Enums\StatusCode;
use WireHttp\Http\Request;
use WireHttp\Http\Response;

/**
 * ServerException — HTTP 5xx Server Error
 *
 * Thrown when the server returns a 5xx status code, indicating that the request
 * was valid and well-formed but the server failed to fulfil it due to an
 * internal problem.
 *
 * Like ClientException, a ServerException always has a response available.
 *
 * Common 5xx codes WireHTTP may throw this for:
 *  - 500 Internal Server Error
 *  - 502 Bad Gateway (upstream proxy failure)
 *  - 503 Service Unavailable (server overloaded / in maintenance)
 *  - 504 Gateway Timeout (upstream server didn't respond in time)
 *
 * WireHTTP's `RetryInterceptor` will automatically retry requests that result in
 * ServerException with status codes 502, 503, or 504 (configurable).
 * WireHTTP's `CircuitBreakerInterceptor` will open the circuit after repeated ServerExceptions.
 *
 * Usage:
 *   try {
 *       $response = Wire::get('/api/resource')->send();
 *   } catch (ServerException $e) {
 *       if ($e->isServiceUnavailable()) {
 *           // Server is down — try again later
 *       } elseif ($e->isGatewayError()) {
 *           // Upstream proxy/gateway failed
 *       }
 *   }
 */
class ServerException extends WireHttpException
{
    public function __construct(
        ?Request $request = null,
        ?Response $response = null,
        string $message = '',
        ?\Throwable $previous = null,
        array $context = [],
    ) {
        $statusCode   = $response?->getStatusCode() ?? 0;
        $reasonPhrase = $response?->getReasonPhrase() ?? '';

        $finalMessage = $message ?: sprintf(
            'Server error: %s %s returned %d %s',
            strtoupper($request?->getMethod() ?? 'UNKNOWN'),
            (string) ($request?->getUri() ?? ''),
            $statusCode,
            $reasonPhrase,
        );

        parent::__construct(
            message: $finalMessage,
            code: $statusCode,
            previous: $previous,
            request: $request,
            response: $response,
            context: array_merge($context, [
                'status_code'   => $statusCode,
                'reason_phrase' => $reasonPhrase,
            ]),
        );
    }

    /**
     * Returns the raw integer HTTP status code.
     */
    public function getStatusCode(): int
    {
        return $this->response?->getStatusCode() ?? 0;
    }

    /**
     * Returns the status code as a type-safe StatusCode enum, or null for non-standard codes.
     */
    public function getStatusCodeEnum(): ?StatusCode
    {
        return StatusCode::tryFromCode($this->getStatusCode());
    }

    /**
     * Returns the HTTP reason phrase associated with the status code.
     */
    public function getReasonPhrase(): string
    {
        return $this->response?->getReasonPhrase() ?? '';
    }

    /**
     * Returns the raw response body. Useful for capturing error details logged by the server.
     */
    public function getResponseBody(): string
    {
        if ($this->response === null) {
            return '';
        }

        $body = $this->response->getBody();
        $body->rewind();

        return $body->getContents();
    }

    /**
     * Returns true if this is a 503 Service Unavailable response.
     * This often indicates the server is under maintenance or overloaded.
     * WireHTTP's RetryInterceptor will automatically retry on this code.
     */
    public function isServiceUnavailable(): bool
    {
        return $this->getStatusCode() === StatusCode::SERVICE_UNAVAILABLE->value;
    }

    /**
     * Returns true if this is a 502 Bad Gateway or 504 Gateway Timeout,
     * meaning an upstream proxy or gateway in front of the actual server failed.
     */
    public function isGatewayError(): bool
    {
        return in_array(
            $this->getStatusCode(),
            [StatusCode::BAD_GATEWAY->value, StatusCode::GATEWAY_TIMEOUT->value],
            strict: true
        );
    }

    /**
     * Returns true if this is a 500 Internal Server Error.
     */
    public function isInternalServerError(): bool
    {
        return $this->getStatusCode() === StatusCode::INTERNAL_SERVER_ERROR->value;
    }

    /**
     * Returns true if this exception represents a status code that is
     * generally safe to retry. WireHTTP's retry logic uses this internally.
     *
     * Retryable codes: 502, 503, 504
     * NOT retryable by default: 500, 501, 505, 506, 507, etc.
     */
    public function isRetryable(): bool
    {
        return in_array(
            $this->getStatusCode(),
            [
                StatusCode::BAD_GATEWAY->value,
                StatusCode::SERVICE_UNAVAILABLE->value,
                StatusCode::GATEWAY_TIMEOUT->value,
            ],
            strict: true
        );
    }

    /**
     * Returns the number of seconds to wait before retrying, based on the
     * server's Retry-After header. Returns null if the header is not present.
     */
    public function getRetryAfterSeconds(): ?int
    {
        if (!($this->response?->hasHeader('Retry-After') ?? false)) {
            return null;
        }

        $value = $this->response->getHeaderLine('Retry-After');

        if (is_numeric($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? max(0, $timestamp - time()) : null;
    }
}
