<?php

declare(strict_types=1);

namespace WireHttp\Exceptions;

use WireHttp\Enums\StatusCode;
use WireHttp\Http\Request;
use WireHttp\Http\Response;

/**
 * ClientException — HTTP 4xx Client Error
 *
 * Thrown when the server returns a 4xx status code, indicating that the request
 * sent by the client was malformed, unauthorized, forbidden, or not found.
 *
 * Unlike NetworkException, a ClientException always has a response available,
 * because the server successfully processed the TCP connection and returned an
 * HTTP response — it just happened to be an error response.
 *
 * WireHTTP throws specific subtypes for the most common 4xx errors to make
 * error handling clean and expressive, while all others throw this base class:
 *
 * StatusCode::BAD_REQUEST (400)            -> ClientException
 * StatusCode::UNAUTHORIZED (401)           -> ClientException
 * StatusCode::FORBIDDEN (403)              -> ClientException
 * StatusCode::NOT_FOUND (404)              -> ClientException
 * StatusCode::METHOD_NOT_ALLOWED (405)     -> ClientException
 * StatusCode::TOO_MANY_REQUESTS (429)      -> ClientException (auto-handled by RateLimitInterceptor)
 * StatusCode::UNPROCESSABLE_CONTENT (422)  -> ClientException
 * ... all other 4xx                        -> ClientException
 *
 * Usage:
 *   try {
 *       $response = Wire::get('/api/resource/999')->send();
 *   } catch (ClientException $e) {
 *       $statusCode = $e->getStatusCode();       // int: 404
 *       $statusEnum = $e->getStatusCodeEnum();   // StatusCode::NOT_FOUND
 *       $body       = $e->getResponseBody();     // string: '{"error":"Not found"}'
 *   }
 */
class ClientException extends WireHttpException
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

        $finalMessage = $message ?: static::buildMessage(
            method: $request?->getMethod() ?? 'UNKNOWN',
            uri: (string) ($request?->getUri() ?? ''),
            statusCode: $statusCode,
            reasonPhrase: $reasonPhrase,
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
     * Returns the raw integer HTTP status code (e.g., 404).
     */
    public function getStatusCode(): int
    {
        return $this->response?->getStatusCode() ?? 0;
    }

    /**
     * Returns the status code as a type-safe StatusCode enum.
     * Returns null if the server returned a non-standard code not in the enum.
     */
    public function getStatusCodeEnum(): ?StatusCode
    {
        return StatusCode::tryFromCode($this->getStatusCode());
    }

    /**
     * Returns the HTTP reason phrase (e.g., "Not Found", "Forbidden").
     */
    public function getReasonPhrase(): string
    {
        return $this->response?->getReasonPhrase() ?? '';
    }

    /**
     * Returns the raw response body string. Useful for logging API error messages.
     * This reads the entire body into memory — do not use for streaming responses.
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
     * Returns the response body decoded as a PHP array (from JSON).
     * Returns null if the body is not valid JSON.
     *
     * @return array<string, mixed>|null
     */
    public function getJsonBody(): ?array
    {
        $raw = $this->getResponseBody();

        if (empty($raw)) {
            return null;
        }

        $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Returns true if the server included a Retry-After header in the response.
     * This is common on 429 Too Many Requests responses.
     */
    public function hasRetryAfter(): bool
    {
        return $this->response?->hasHeader('Retry-After') ?? false;
    }

    /**
     * Returns the value of the Retry-After header in seconds, if present.
     * Returns null if the header is absent or cannot be parsed.
     */
    public function getRetryAfterSeconds(): ?int
    {
        if (!$this->hasRetryAfter()) {
            return null;
        }

        $value = $this->response->getHeaderLine('Retry-After');

        // Retry-After can be either a number of seconds or an HTTP-date
        if (is_numeric($value)) {
            return (int) $value;
        }

        // Try to parse an HTTP-date format
        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return max(0, $timestamp - time());
    }

    /**
     * Factory method: create the appropriate exception for a given Response.
     *
     * This intelligently creates either a ClientException (4xx) or a
     * ServerException (5xx) based on the response's status code.
     * This is the primary way WireHTTP's HTTP error middleware creates exceptions.
     */
    public static function fromResponse(Request $request, Response $response): static|ServerException
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode >= 500) {
            return new ServerException(request: $request, response: $response);
        }

        return new static(request: $request, response: $response);
    }

    private static function buildMessage(
        string $method,
        string $uri,
        int $statusCode,
        string $reasonPhrase,
    ): string {
        return sprintf(
            'HTTP request failed: %s %s returned %d %s',
            strtoupper($method),
            $uri,
            $statusCode,
            $reasonPhrase,
        );
    }
}
