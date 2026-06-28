<?php

declare(strict_types=1);

namespace WireHttp\Response;

use WireHttp\Http\Response;
use WireHttp\Http\Stream;
use WireHttp\Response\Hydrator\AttributeHydrator;
use WireHttp\Response\Hydrator\HydratorInterface;
use WireHttp\Response\Hydrator\HydrationException;
use WireHttp\Response\Sse\SseParser;
use WireHttp\Response\Sse\SseEvent;

/**
 * ResponseDecorator — Developer-Facing Fluent Response Wrapper
 *
 * The `ResponseDecorator` wraps a raw `Response` object and exposes a
 * rich, developer-friendly API for consuming HTTP responses. It is
 * the object returned by `Wire::get()`, `Wire::post()`, etc.
 *
 * Design philosophy:
 * ------------------
 * The core `Response` class is a pure PSR-7 value object — minimal, immutable,
 * no business logic. The `ResponseDecorator` is the ergonomic layer on top
 * that most application code interacts with. Separation of concerns.
 *
 * All body reads are LAZY and CACHED:
 * ------------------------------------
 * The first call to `body()`, `json()`, `text()`, etc. reads and caches the
 * decoded content. Subsequent calls return the cached value without re-reading
 * the stream. This means you can call `$response->json()` multiple times
 * without worrying about stream position.
 *
 * Usage:
 *   $response = Wire::get('https://api.example.com/users/1');
 *
 *   // Status checks
 *   $response->isOk();            // 200
 *   $response->isSuccessful();    // 2xx
 *   $response->isNotFound();      // 404
 *   $response->isServerError();   // 5xx
 *   $response->throw();           // throws HttpClientException on 4xx/5xx
 *
 *   // Body reading
 *   $response->json();            // array: decoded JSON
 *   $response->json('user.name'); // "Alice": dot-notation access
 *   $response->text();            // string: raw body
 *   $response->body();            // string: alias for text()
 *
 *   // DTO Hydration
 *   $user = $response->into(UserDto::class);
 *
 *   // SSE Streaming
 *   foreach ($response->events() as $event) {
 *       echo $event->json()['content'];
 *   }
 *
 *   // Header access
 *   $response->header('Content-Type');  // "application/json; charset=utf-8"
 *   $response->headers();               // array of all headers
 */
final class ResponseDecorator
{
    /** Cached raw body string. */
    private ?string $cachedBody = null;

    /** Cached decoded JSON. */
    private array|null|false $cachedJson = false; // false = not-yet-decoded

    /** The hydrator used by `into()`. */
    private readonly HydratorInterface $hydrator;

    public function __construct(
        private readonly Response $response,
        ?HydratorInterface $hydrator = null,
    ) {
        $this->hydrator = $hydrator ?? new AttributeHydrator();
    }

    // ─── Status Inspection ────────────────────────────────────────────────────

    /**
     * Returns the HTTP status code integer (e.g., 200, 404, 500).
     */
    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * Returns the HTTP reason phrase (e.g., "OK", "Not Found").
     */
    public function reason(): string
    {
        return $this->response->getReasonPhrase();
    }

    /**
     * Returns true if status code is exactly 200.
     */
    public function isOk(): bool
    {
        return $this->response->getStatusCode() === 200;
    }

    /**
     * Returns true if status code is 201 Created.
     */
    public function isCreated(): bool
    {
        return $this->response->getStatusCode() === 201;
    }

    /**
     * Returns true if status code is 204 No Content.
     */
    public function isNoContent(): bool
    {
        return $this->response->getStatusCode() === 204;
    }

    /**
     * Returns true if the status code is 2xx (success range).
     */
    public function isSuccessful(): bool
    {
        return $this->response->getStatusCode() >= 200 && $this->response->getStatusCode() < 300;
    }

    /**
     * Returns true if the status code is 3xx (redirect).
     */
    public function isRedirect(): bool
    {
        return $this->response->getStatusCode() >= 300 && $this->response->getStatusCode() < 400;
    }

    /**
     * Returns true if the status code is 400.
     */
    public function isBadRequest(): bool
    {
        return $this->response->getStatusCode() === 400;
    }

    /**
     * Returns true if the status code is 401 Unauthorized.
     */
    public function isUnauthorized(): bool
    {
        return $this->response->getStatusCode() === 401;
    }

    /**
     * Returns true if the status code is 403 Forbidden.
     */
    public function isForbidden(): bool
    {
        return $this->response->getStatusCode() === 403;
    }

    /**
     * Returns true if the status code is 404 Not Found.
     */
    public function isNotFound(): bool
    {
        return $this->response->getStatusCode() === 404;
    }

    /**
     * Returns true if the status code is 422 Unprocessable Entity.
     */
    public function isUnprocessable(): bool
    {
        return $this->response->getStatusCode() === 422;
    }

    /**
     * Returns true if the status code is 429 Too Many Requests.
     */
    public function isTooManyRequests(): bool
    {
        return $this->response->getStatusCode() === 429;
    }

    /**
     * Returns true if the status code is 4xx (client error).
     */
    public function isClientError(): bool
    {
        return $this->response->isClientError();
    }

    /**
     * Returns true if the status code is 5xx (server error).
     */
    public function isServerError(): bool
    {
        return $this->response->isServerError();
    }

    /**
     * Returns true if the status code is 4xx OR 5xx.
     */
    public function failed(): bool
    {
        return $this->isClientError() || $this->isServerError();
    }

    // ─── Throwing on Error ────────────────────────────────────────────────────

    /**
     * Throws an HttpClientException if the response is a 4xx or 5xx.
     * Returns $this for fluent chaining when successful.
     *
     * Usage: Wire::get('/api')->throw()->json()['data'];
     *
     * @throws \WireHttp\Exceptions\HttpClientException
     * @throws \WireHttp\Exceptions\ClientException
     * @throws \WireHttp\Exceptions\ServerException
     */
    public function throw(): static
    {
        if ($this->isClientError()) {
            $message = sprintf(
                'HTTP Client Error: %d %s — %s',
                $this->status(),
                $this->response->getReasonPhrase(),
                $this->text()
            );

            throw new \WireHttp\Exceptions\ClientException(null, $this->response, $message);
        }

        if ($this->isServerError()) {
            $message = sprintf(
                'HTTP Server Error: %d %s',
                $this->status(),
                $this->response->getReasonPhrase()
            );

            throw new \WireHttp\Exceptions\ServerException(null, $this->response, $message);
        }

        return $this;
    }

    /**
     * Throws only if the given condition is true. Useful for conditional error handling.
     *
     * Usage: $response->throwIf($response->status() === 422);
     *
     * @throws \WireHttp\Exceptions\HttpClientException|\WireHttp\Exceptions\HttpServerException
     */
    public function throwIf(bool $condition): static
    {
        if ($condition) {
            $this->throw();
        }

        return $this;
    }

    /**
     * Throws unless the given condition is true.
     *
     * Usage: $response->throwUnless($response->isOk());
     *
     * @throws \WireHttp\Exceptions\HttpClientException|\WireHttp\Exceptions\HttpServerException
     */
    public function throwUnless(bool $condition): static
    {
        return $this->throwIf(!$condition);
    }

    // ─── Body Reading ─────────────────────────────────────────────────────────

    /**
     * Returns the raw response body as a string.
     * The result is cached — the stream is read at most once.
     */
    public function body(): string
    {
        if ($this->cachedBody !== null) {
            return $this->cachedBody;
        }

        $stream = $this->response->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return $this->cachedBody = $stream->getContents();
    }

    /**
     * Alias for body(). Returns the raw response body.
     */
    public function text(): string
    {
        return $this->body();
    }

    /**
     * Decodes the response body as JSON and returns it as an array.
     * Returns null if the body is empty or not valid JSON.
     *
     * Supports dot-notation access to deeply nested keys:
     *   $response->json('user.address.city') // "London"
     *
     * @param string|null $key Optional dot-notation key to access.
     * @return mixed The full decoded array, a nested value, or null.
     */
    public function json(?string $key = null): mixed
    {
        if ($this->cachedJson === false) {
            $body = $this->body();

            if ($body === '') {
                $this->cachedJson = null;
            } else {
                try {
                    $decoded          = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
                    $this->cachedJson = is_array($decoded) ? $decoded : null;
                } catch (\JsonException) {
                    $this->cachedJson = null;
                }
            }
        }

        if ($key === null) {
            return $this->cachedJson;
        }

        return $this->dotGet($this->cachedJson ?? [], $key);
    }

    /**
     * Hydrates the JSON response body into an instance of the given DTO class.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     * @throws HydrationException
     */
    public function into(string $class): object
    {
        return $this->hydrator->hydrate($class, $this->response);
    }

    /**
     * Returns the response body decoded as an XML SimpleXMLElement.
     * Returns null if parsing fails.
     */
    public function xml(): ?\SimpleXMLElement
    {
        $body = $this->body();

        if ($body === '') {
            return null;
        }

        set_error_handler(static fn() => true);
        $xml = simplexml_load_string($body);
        restore_error_handler();

        return $xml === false ? null : $xml;
    }

    /**
     * Parses the response as a Server-Sent Events stream and returns a Generator.
     *
     * @param SseParser|null $parser Custom SSE parser (uses defaults if null).
     * @return \Generator<int, SseEvent>
     */
    public function events(?SseParser $parser = null): \Generator
    {
        return ($parser ?? new SseParser())->parse($this->response);
    }

    /**
     * Returns the response body as a Stream object for manual streaming.
     */
    public function stream(): Stream
    {
        return Stream::fromResource($this->response->getBody()->detach());
    }

    // ─── Header Access ────────────────────────────────────────────────────────

    /**
     * Returns the value of a specific header.
     * If the header has multiple values, they are joined with ", ".
     * Returns empty string if the header is not present.
     */
    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    /**
     * Returns all response headers as an associative array.
     * Header names are in their original casing from the server.
     *
     * @return array<string, list<string>>
     */
    public function headers(): array
    {
        return $this->response->getHeaders();
    }

    /**
     * Returns true if the given header is present in the response.
     */
    public function hasHeader(string $name): bool
    {
        return $this->response->hasHeader($name);
    }

    /**
     * Returns the value of the Content-Type header.
     */
    public function contentType(): string
    {
        return $this->response->getHeaderLine('Content-Type');
    }

    /**
     * Returns the Content-Length in bytes, or null if not provided.
     */
    public function contentLength(): ?int
    {
        $len = $this->response->getHeaderLine('Content-Length');

        return $len !== '' ? (int) $len : null;
    }

    // ─── Delegation to Raw Response ───────────────────────────────────────────

    /**
     * Returns the underlying raw PSR-7 Response object.
     */
    public function toResponse(): Response
    {
        return $this->response;
    }

    /**
     * Returns the HTTP protocol version (e.g., "1.1", "2").
     */
    public function protocolVersion(): string
    {
        return $this->response->getProtocolVersion();
    }

    // ─── Private: Dot-Notation Key Access ─────────────────────────────────────

    /**
     * Traverses a nested array using dot-notation.
     *
     * @param array<string, mixed> $data
     * @param string $key
     * @return mixed
     */
    private function dotGet(array $data, string $key): mixed
    {
        $parts   = explode('.', $key);
        $current = $data;

        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }

            $current = $current[$part];
        }

        return $current;
    }

    // ─── Magic ───────────────────────────────────────────────────────────────

    /**
     * Allows casting the response to a string (returns the raw body).
     */
    public function __toString(): string
    {
        return $this->body();
    }
}
