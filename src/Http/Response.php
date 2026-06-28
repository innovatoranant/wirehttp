<?php

declare(strict_types=1);

namespace WireHttp\Http;

use WireHttp\Enums\StatusCode;

/**
 * Response — Immutable HTTP Response Message
 *
 * Represents a single inbound HTTP response received from a server.
 * Like Request, every property is immutable — all mutation methods return
 * a new cloned instance. This makes Response objects safe to cache, pass
 * through middleware chains, and share across async Fiber contexts.
 *
 * The Response contains everything received from the server:
 *   - The HTTP status code (and reason phrase)
 *   - The HTTP protocol version
 *   - The response headers
 *   - The response body (Stream)
 *
 * WireHTTP's Response also carries high-level convenience methods that
 * developers use constantly, such as `json()`, `text()`, `ok()`, `redirect()`.
 * This eliminates the need for a separate "ResponseDecorator" wrapper for common cases.
 *
 * Usage (typically constructed by the Transport layer, not directly):
 *   $response = new Response(200, ['Content-Type' => 'application/json'], $stream);
 *   $data     = $response->json();     // Decodes body as array
 *   $object   = $response->object();   // Decodes body as stdClass
 */
final class Response
{
    use MessageTrait;

    /**
     * The HTTP status code (100–599).
     */
    private int $statusCode;

    /**
     * The HTTP reason phrase (e.g., "OK", "Not Found").
     * If empty, we derive it from the status code enum when needed.
     */
    private string $reasonPhrase;

    /**
     * Cached decoded JSON body. Populated lazily on first `json()` call.
     * Avoids decoding the same JSON body multiple times.
     *
     * @var array<mixed>|null
     */
    private ?array $decodedJson = null;

    /**
     * Tracks whether the body has been consumed (read to EOF).
     * Used for streaming responses to prevent accidental double-reads.
     */
    private bool $bodyConsumed = false;

    public function __construct(
        int     $statusCode,
        array   $headers    = [],
        ?Stream $body       = null,
        string  $version    = '1.1',
        string  $reasonPhrase = '',
    ) {
        $this->assertValidStatusCode($statusCode);

        $this->statusCode     = $statusCode;
        $this->headers        = new Headers($headers);
        $this->body           = $body ?? Stream::empty();
        $this->protocolVersion = $version;

        // If no reason phrase given, derive from the enum
        $this->reasonPhrase = $reasonPhrase !== ''
            ? $reasonPhrase
            : (StatusCode::tryFromCode($statusCode)?->reasonPhrase() ?? '');
    }

    // ─── Status ───────────────────────────────────────────────────────────────

    /**
     * Returns the HTTP status code as an integer (e.g., 200, 404, 500).
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Returns the HTTP reason phrase associated with the status code.
     */
    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    /**
     * Returns the status code as a type-safe StatusCode enum.
     * Returns null if the server returned a non-standard code.
     */
    public function getStatusEnum(): ?StatusCode
    {
        return StatusCode::tryFromCode($this->statusCode);
    }

    /**
     * Returns a new instance with the given status code and optional reason phrase.
     */
    public function withStatus(int $code, string $reasonPhrase = ''): static
    {
        $this->assertValidStatusCode($code);

        $clone               = clone $this;
        $clone->statusCode   = $code;
        $clone->reasonPhrase = $reasonPhrase !== ''
            ? $reasonPhrase
            : (StatusCode::tryFromCode($code)?->reasonPhrase() ?? '');
        $clone->decodedJson = null; // Invalidate JSON cache

        return $clone;
    }

    // ─── Status Checks (Boolean shortcuts) ───────────────────────────────────

    /** Returns true if status is 2xx (Success). */
    public function ok(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /** Returns true if status is exactly 200 OK. */
    public function isOk(): bool
    {
        return $this->statusCode === 200;
    }

    /** Returns true if status is exactly 201 Created. */
    public function isCreated(): bool
    {
        return $this->statusCode === 201;
    }

    /** Returns true if status is exactly 204 No Content. */
    public function isNoContent(): bool
    {
        return $this->statusCode === 204;
    }

    /** Returns true if status is 3xx Redirect. */
    public function isRedirect(): bool
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }

    /** Returns true if status is 4xx Client Error. */
    public function isClientError(): bool
    {
        return $this->statusCode >= 400 && $this->statusCode < 500;
    }

    /** Returns true if status is exactly 401 Unauthorized. */
    public function isUnauthorized(): bool
    {
        return $this->statusCode === 401;
    }

    /** Returns true if status is exactly 403 Forbidden. */
    public function isForbidden(): bool
    {
        return $this->statusCode === 403;
    }

    /** Returns true if status is exactly 404 Not Found. */
    public function isNotFound(): bool
    {
        return $this->statusCode === 404;
    }

    /** Returns true if status is exactly 429 Too Many Requests. */
    public function isTooManyRequests(): bool
    {
        return $this->statusCode === 429;
    }

    /** Returns true if status is 5xx Server Error. */
    public function isServerError(): bool
    {
        return $this->statusCode >= 500 && $this->statusCode < 600;
    }

    /** Returns true if status is 4xx or 5xx (any error). */
    public function failed(): bool
    {
        return $this->statusCode >= 400;
    }

    // ─── Body Decoding ────────────────────────────────────────────────────────

    /**
     * Returns the response body as a raw string.
     * Rewinds the stream before reading if the stream is seekable.
     *
     * @throws \RuntimeException if the body stream is not readable.
     */
    public function text(): string
    {
        if ($this->body->isSeekable()) {
            $this->body->rewind();
        }

        $content           = $this->body->getContents();
        $this->bodyConsumed = true;

        return $content;
    }

    /**
     * Returns the response body decoded as a PHP associative array.
     * The result is cached — multiple calls do NOT re-decode the JSON.
     *
     * @param bool $throw If true, throws \JsonException on decode failure.
     *                    If false, returns null on invalid JSON (default: true).
     *
     * @return array<mixed>|null
     * @throws \JsonException if JSON decoding fails and $throw is true.
     */
    public function json(bool $throw = true): ?array
    {
        if ($this->decodedJson !== null) {
            return $this->decodedJson;
        }

        $raw = $this->text();

        if ($raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            if ($throw) {
                throw $e;
            }

            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        return $this->decodedJson = $decoded;
    }

    /**
     * Returns the response body decoded as an stdClass object (not an array).
     * Useful for property-access syntax: $response->object()->data->id
     *
     * @throws \JsonException if JSON decoding fails.
     */
    public function object(): ?\stdClass
    {
        $raw = $this->text();

        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, associative: false, flags: JSON_THROW_ON_ERROR);

        return $decoded instanceof \stdClass ? $decoded : null;
    }

    /**
     * Hydrates the response JSON body into a typed PHP class or DTO.
     *
     * The target class should have public readonly properties matching the
     * JSON keys, or implement a `fromArray(array $data): static` static method.
     *
     * @template T of object
     * @param class-string<T> $class The target class to hydrate into.
     * @return T
     * @throws \RuntimeException if hydration fails.
     */
    public function hydrate(string $class): object
    {
        $data = $this->json();

        if ($data === null) {
            throw new \RuntimeException(
                sprintf('Cannot hydrate %s: response body is not valid JSON.', $class)
            );
        }

        // Support classes with a static fromArray() factory method
        if (method_exists($class, 'fromArray')) {
            return $class::fromArray($data);
        }

        // Use reflection to map array keys to readonly constructor parameters
        try {
            $reflection  = new \ReflectionClass($class);
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return $reflection->newInstance();
            }

            $params = [];

            foreach ($constructor->getParameters() as $param) {
                $name           = $param->getName();
                $params[$name]  = $data[$name] ?? ($param->isDefaultValueAvailable() ? $param->getDefaultValue() : null);
            }

            return $reflection->newInstanceArgs($params);
        } catch (\ReflectionException $e) {
            throw new \RuntimeException(
                sprintf('Failed to hydrate %s: %s', $class, $e->getMessage()),
                previous: $e
            );
        }
    }

    /**
     * Returns the response body as a Stream (for streaming / chunked processing).
     * The stream is not rewound — you control the read position.
     */
    public function stream(): Stream
    {
        return $this->body;
    }

    // ─── Header Shortcuts ─────────────────────────────────────────────────────

    /**
     * Returns the Location header value (used in redirect responses).
     */
    public function getLocation(): ?string
    {
        $location = $this->getHeaderLine('Location');

        return $location !== '' ? $location : null;
    }

    /**
     * Returns the Retry-After header value in seconds, if present.
     * Returns null if not present or not parseable.
     */
    public function getRetryAfterSeconds(): ?int
    {
        $value = $this->getHeaderLine('Retry-After');

        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? max(0, $timestamp - time()) : null;
    }

    /**
     * Returns the Content-Length header value in bytes, or null if not present.
     */
    public function getContentLength(): ?int
    {
        $value = $this->getHeaderLine('Content-Length');

        return $value !== '' ? (int) $value : null;
    }

    /**
     * Returns the ETag header value, or null if not present.
     * Strips surrounding quotes from weak/strong ETags.
     */
    public function getEtag(): ?string
    {
        $etag = $this->getHeaderLine('ETag');

        if ($etag === '') {
            return null;
        }

        return trim($etag, '"');
    }

    /**
     * Returns all Set-Cookie headers as an array of raw cookie strings.
     *
     * @return list<string>
     */
    public function getCookieHeaders(): array
    {
        return $this->getHeader('Set-Cookie');
    }

    // ─── Debug / Logging ─────────────────────────────────────────────────────

    /**
     * Returns a human-readable summary of the response for logging.
     */
    public function toLogString(): string
    {
        return sprintf(
            'HTTP/%s %d %s [%d bytes]',
            $this->protocolVersion,
            $this->statusCode,
            $this->reasonPhrase,
            $this->body->getSize() ?? -1,
        );
    }

    // ─── Private Helpers ─────────────────────────────────────────────────────

    private function assertValidStatusCode(int $code): void
    {
        if ($code < 100 || $code > 599) {
            throw new \InvalidArgumentException(
                sprintf('Invalid HTTP status code %d. Must be between 100 and 599.', $code)
            );
        }
    }
}
