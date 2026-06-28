<?php

declare(strict_types=1);

namespace WireHttp\Http;

use WireHttp\Enums\HttpMethod;

/**
 * Request — Immutable HTTP Request Message
 *
 * Represents a single outbound HTTP request. Every property is immutable —
 * all mutations return a new cloned instance. This makes Request objects safe
 * to pass between middleware layers, Fiber contexts, and async handlers.
 *
 * The Request contains everything the transport layer needs to physically
 * send an HTTP request over the wire:
 *   - The HTTP method (GET, POST, etc.)
 *   - The target URI
 *   - The HTTP protocol version
 *   - The request headers
 *   - The request body (Stream)
 *
 * Additionally, WireHTTP's Request carries:
 *   - Options: a typed array of per-request settings (timeout, ssl, auth, etc.)
 *     that are passed through to the transport and middleware layers. This is
 *     WireHTTP's replacement for Guzzle's giant `$options` array — but strictly typed.
 *   - Attributes: an arbitrary key-value store for middleware to pass data
 *     between themselves without polluting headers or the body.
 *
 * Usage (via RequestBuilder — you typically never construct this directly):
 *   $request = new Request(HttpMethod::POST, new Uri('https://api.example.com/users'));
 *   $request = $request
 *       ->withHeader('Content-Type', 'application/json')
 *       ->withBodyContent('{"name":"Alice"}');
 */
final class Request
{
    use MessageTrait;

    /**
     * The HTTP request method.
     * We store both the enum and a string so the transport layer can read
     * the string without an enum unwrap in a hot path.
     */
    private HttpMethod $method;

    /**
     * The request target URI.
     */
    private Uri $uri;

    /**
     * The "request target" string sent on the HTTP wire.
     * This is typically the URI path + query (e.g., "/api/users?page=2"),
     * but for CONNECT requests it is the host:port, and for OPTIONS it can be "*".
     * If null, it is computed lazily from the URI.
     */
    private ?string $requestTarget;

    /**
     * Per-request options. This is WireHTTP's replacement for Guzzle's untyped options array.
     * Keys are option names (strings), values are strictly typed option objects or scalars.
     * This is passed through the stack and read by the transport and middleware layers.
     *
     * @var array<string, mixed>
     */
    private array $options;

    /**
     * Middleware attributes: arbitrary data passed between middleware layers.
     * These are never serialized onto the wire — purely internal stack communication.
     *
     * @var array<string, mixed>
     */
    private array $attributes;

    public function __construct(
        HttpMethod|string $method,
        Uri|string        $uri,
        array             $headers    = [],
        ?Stream           $body       = null,
        string            $version    = '1.1',
        array             $options    = [],
        array             $attributes = [],
        ?string           $requestTarget = null,
    ) {
        $this->method          = $method instanceof HttpMethod ? $method : HttpMethod::fromString($method);
        $this->uri             = $uri instanceof Uri ? $uri : new Uri((string) $uri);
        $this->headers         = new Headers($headers);
        $this->body            = $body ?? Stream::empty();
        $this->protocolVersion = $version;
        $this->options         = $options;
        $this->attributes      = $attributes;
        $this->requestTarget   = $requestTarget;

        // Automatically set the Host header from the URI if not already present
        if (!$this->headers->has('Host') && $this->uri->getHost() !== '') {
            $host = $this->uri->getHost();

            $port = $this->uri->getPort();

            if ($port !== null && !$this->uri->isDefaultPort()) {
                $host .= ':' . $port;
            }

            $this->headers = $this->headers->set('Host', $host);
        }
    }

    // ─── Method ───────────────────────────────────────────────────────────────

    /**
     * Returns the HTTP method as a string (e.g., "GET", "POST").
     */
    public function getMethod(): string
    {
        return $this->method->value;
    }

    /**
     * Returns the HTTP method as a type-safe HttpMethod enum.
     */
    public function getMethodEnum(): HttpMethod
    {
        return $this->method;
    }

    /**
     * Returns a new instance with the given HTTP method.
     */
    public function withMethod(HttpMethod|string $method): static
    {
        $enum = $method instanceof HttpMethod ? $method : HttpMethod::fromString($method);

        if ($enum === $this->method) {
            return $this;
        }

        $clone         = clone $this;
        $clone->method = $enum;

        return $clone;
    }

    // ─── URI ──────────────────────────────────────────────────────────────────

    /**
     * Returns the request URI.
     */
    public function getUri(): Uri
    {
        return $this->uri;
    }

    /**
     * Returns a new instance with the given URI.
     *
     * @param bool $preserveHost If true, the Host header is not updated from the new URI.
     *                           Per RFC 7230, if the URI has a host, the Host header should
     *                           be updated to match. Set to false (default) for correct behaviour.
     */
    public function withUri(Uri|string $uri, bool $preserveHost = false): static
    {
        $newUri = $uri instanceof Uri ? $uri : new Uri((string) $uri);

        if ((string) $newUri === (string) $this->uri) {
            return $this;
        }

        $clone      = clone $this;
        $clone->uri = $newUri;
        $clone->requestTarget = null; // Invalidate cached request target

        if (!$preserveHost && $newUri->getHost() !== '') {
            $host = $newUri->getHost();

            $port = $newUri->getPort();

            if ($port !== null && !$newUri->isDefaultPort()) {
                $host .= ':' . $port;
            }

            $clone->headers = $clone->headers->set('Host', $host);
        }

        return $clone;
    }

    // ─── Request Target ───────────────────────────────────────────────────────

    /**
     * Returns the request-target string that will appear on the HTTP start line.
     * For most requests this is the path and query (e.g., "/api/users?page=1").
     * For CONNECT requests it is the host:port. For OPTIONS it can be "*".
     */
    public function getRequestTarget(): string
    {
        if ($this->requestTarget !== null) {
            return $this->requestTarget;
        }

        $path  = $this->uri->getPath();
        $query = $this->uri->getQuery();

        if ($path === '') {
            $path = '/';
        }

        if ($query !== '') {
            $path .= '?' . $query;
        }

        return $this->requestTarget = $path;
    }

    /**
     * Returns a new instance with a specific request target override.
     * Use this for CONNECT tunneling (host:port) or OPTIONS ("*") requests.
     */
    public function withRequestTarget(string $requestTarget): static
    {
        if ($requestTarget === $this->requestTarget) {
            return $this;
        }

        $clone                = clone $this;
        $clone->requestTarget = $requestTarget;

        return $clone;
    }

    // ─── Options ──────────────────────────────────────────────────────────────

    /**
     * Returns the full options array for this request.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Returns a specific option value, or $default if not set.
     */
    public function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /**
     * Returns true if a specific option key is set.
     */
    public function hasOption(string $key): bool
    {
        return array_key_exists($key, $this->options);
    }

    /**
     * Returns a new instance with the given option set.
     */
    public function withOption(string $key, mixed $value): static
    {
        $clone               = clone $this;
        $clone->options[$key] = $value;

        return $clone;
    }

    /**
     * Returns a new instance with all options merged.
     *
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        $clone          = clone $this;
        $clone->options = array_merge($this->options, $options);

        return $clone;
    }

    // ─── Attributes ───────────────────────────────────────────────────────────

    /**
     * Returns all middleware attributes.
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Returns a specific middleware attribute value, or $default if not set.
     */
    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    /**
     * Returns a new instance with the given attribute set.
     * Middleware uses this to attach data for downstream layers.
     */
    public function withAttribute(string $name, mixed $value): static
    {
        $clone                    = clone $this;
        $clone->attributes[$name] = $value;

        return $clone;
    }

    /**
     * Returns a new instance with the given attribute removed.
     */
    public function withoutAttribute(string $name): static
    {
        if (!array_key_exists($name, $this->attributes)) {
            return $this;
        }

        $clone = clone $this;
        unset($clone->attributes[$name]);

        return $clone;
    }

    // ─── Debug / Utility ──────────────────────────────────────────────────────

    /**
     * Returns a ready-to-use `curl` shell command that reproduces this request.
     * Invaluable for debugging — copy-paste into a terminal.
     */
    public function toCurlCommand(): string
    {
        $parts = ['curl', '-X', escapeshellarg($this->getMethod())];

        foreach ($this->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $parts[] = '-H ' . escapeshellarg("{$name}: {$value}");
            }
        }

        $body = $this->body;

        if ($body->getSize() !== null && $body->getSize() > 0) {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            $parts[] = '--data ' . escapeshellarg($body->getContents());
        }

        $parts[] = escapeshellarg((string) $this->uri);

        return implode(' ', $parts);
    }

    /**
     * Returns a human-readable summary of the request for logging.
     */
    public function toLogString(): string
    {
        return sprintf(
            '%s %s HTTP/%s',
            $this->getMethod(),
            $this->getRequestTarget(),
            $this->protocolVersion,
        );
    }
}
