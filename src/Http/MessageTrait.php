<?php

declare(strict_types=1);

namespace WireHttp\Http;

/**
 * MessageTrait — Shared Immutable HTTP Message Behavior
 *
 * This trait provides the common header and body management logic shared
 * between Request and Response. It is intentionally a trait (not a base class)
 * so that Request and Response remain independent, composable value objects.
 *
 * All mutation methods return a new cloned instance of the object (copy-on-write),
 * making HTTP messages safe to pass between Fibers, middleware layers, and
 * async contexts without race conditions or unexpected mutation.
 *
 * This trait expects the using class to declare:
 *   protected Headers $headers;
 *   protected Stream  $body;
 *   protected string  $protocolVersion;
 */
trait MessageTrait
{
    protected Headers $headers;
    protected Stream  $body;
    protected string  $protocolVersion = '1.1';

    // ─── Protocol Version ─────────────────────────────────────────────────────

    /**
     * Returns the HTTP protocol version string (e.g., "1.1", "2", "3").
     */
    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    /**
     * Returns a new instance with the specified HTTP protocol version.
     *
     * @param string $version The protocol version string (e.g., "1.1", "2").
     */
    public function withProtocolVersion(string $version): static
    {
        $this->assertValidProtocolVersion($version);

        if ($version === $this->protocolVersion) {
            return $this;
        }

        $clone                  = clone $this;
        $clone->protocolVersion = $version;

        return $clone;
    }

    // ─── Headers ─────────────────────────────────────────────────────────────

    /**
     * Returns all headers as an associative array.
     * Keys are the original-case header names. Values are arrays of strings.
     *
     * @return array<string, list<string>>
     */
    public function getHeaders(): array
    {
        return $this->headers->all();
    }

    /**
     * Returns true if the given header name is present (case-insensitive).
     */
    public function hasHeader(string $name): bool
    {
        return $this->headers->has($name);
    }

    /**
     * Returns all values for a header as an array of strings.
     * Returns an empty array if the header is not present.
     *
     * @return list<string>
     */
    public function getHeader(string $name): array
    {
        return $this->headers->get($name);
    }

    /**
     * Returns all values for a header joined as a single comma-separated string.
     * Returns an empty string if the header is not present.
     */
    public function getHeaderLine(string $name): string
    {
        return $this->headers->getLine($name);
    }

    /**
     * Returns a new instance with the provided header, replacing any existing values.
     *
     * @param string|list<string> $value
     */
    public function withHeader(string $name, string|array $value): static
    {
        $clone          = clone $this;
        $clone->headers = $this->headers->set($name, $value);

        return $clone;
    }

    /**
     * Returns a new instance with the provided header value(s) appended.
     * Does NOT replace existing values — adds to them.
     *
     * @param string|list<string> $value
     */
    public function withAddedHeader(string $name, string|array $value): static
    {
        $clone          = clone $this;
        $clone->headers = $this->headers->add($name, $value);

        return $clone;
    }

    /**
     * Returns a new instance with the specified header removed.
     */
    public function withoutHeader(string $name): static
    {
        if (!$this->headers->has($name)) {
            return $this;
        }

        $clone          = clone $this;
        $clone->headers = $this->headers->remove($name);

        return $clone;
    }

    /**
     * Returns a new instance with all given headers merged in (overwriting existing ones).
     *
     * @param array<string, string|list<string>> $headers
     */
    public function withHeaders(array $headers): static
    {
        $clone          = clone $this;
        $clone->headers = $this->headers->merge($headers);

        return $clone;
    }

    // ─── Content-Type Shortcuts ───────────────────────────────────────────────

    /**
     * Returns the Content-Type header value, or null if not present.
     */
    public function getContentType(): ?string
    {
        $value = $this->getHeaderLine('Content-Type');

        return $value !== '' ? $value : null;
    }

    /**
     * Returns just the media type part of Content-Type, stripping parameters.
     * E.g., "application/json; charset=utf-8" → "application/json"
     */
    public function getMediaType(): ?string
    {
        $contentType = $this->getContentType();

        if ($contentType === null) {
            return null;
        }

        $parts = explode(';', $contentType, 2);

        return strtolower(trim($parts[0]));
    }

    /**
     * Returns true if the Content-Type indicates a JSON body.
     */
    public function isJson(): bool
    {
        $mediaType = $this->getMediaType();

        return $mediaType !== null && (
            $mediaType === 'application/json'
            || str_ends_with($mediaType, '+json')
        );
    }

    /**
     * Returns true if the Content-Type indicates an HTML body.
     */
    public function isHtml(): bool
    {
        return $this->getMediaType() === 'text/html';
    }

    /**
     * Returns true if the Content-Type indicates a form-encoded body.
     */
    public function isForm(): bool
    {
        return $this->getMediaType() === 'application/x-www-form-urlencoded';
    }

    /**
     * Returns true if the Content-Type indicates a multipart body.
     */
    public function isMultipart(): bool
    {
        $mediaType = $this->getMediaType();

        return $mediaType !== null && str_starts_with($mediaType, 'multipart/');
    }

    // ─── Body ─────────────────────────────────────────────────────────────────

    /**
     * Returns the body of the message as a Stream.
     */
    public function getBody(): Stream
    {
        return $this->body;
    }

    /**
     * Returns a new instance with the specified body stream.
     */
    public function withBody(Stream $body): static
    {
        if ($body === $this->body) {
            return $this;
        }

        $clone       = clone $this;
        $clone->body = $body;

        return $clone;
    }

    /**
     * Returns a new instance with the body replaced by the given string content.
     * Also sets the Content-Length header automatically if $setContentLength is true.
     */
    public function withBodyContent(string $content, bool $setContentLength = true): static
    {
        $stream = Stream::fromString($content);
        $clone  = $this->withBody($stream);

        if ($setContentLength) {
            $clone = $clone->withHeader('Content-Length', (string) strlen($content));
        }

        return $clone;
    }

    // ─── Private Helpers ─────────────────────────────────────────────────────

    /**
     * Validates a protocol version string.
     * Accepts: "1.0", "1.1", "2", "2.0", "3", "3.0"
     *
     * @throws \InvalidArgumentException for invalid version strings.
     */
    private function assertValidProtocolVersion(string $version): void
    {
        $valid = ['1.0', '1.1', '2', '2.0', '3', '3.0'];

        if (!in_array($version, $valid, strict: true)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid HTTP protocol version "%s". Supported versions: %s.',
                    $version,
                    implode(', ', $valid)
                )
            );
        }
    }
}
