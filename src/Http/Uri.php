<?php

declare(strict_types=1);

namespace WireHttp\Http;

/**
 * Uri — RFC 3986-Compliant URI Value Object
 *
 * A fully immutable URI value object. Every mutation returns a new instance.
 * Parsing and component extraction follow RFC 3986 precisely, with additional
 * support for query string manipulation (which RFC 3986 leaves to applications).
 *
 * URI Structure per RFC 3986:
 *   scheme "://" [userinfo "@"] host [":" port] path ["?" query] ["#" fragment]
 *   e.g.: https://user:pass@api.example.com:8443/v1/users?page=1&limit=25#results
 *
 * Performance Optimization:
 * -------------------------
 * We use PHP's built-in `parse_url()` for initial parsing (it's implemented in C
 * and is extremely fast). The parsed components are stored and the full URI string
 * is only re-assembled lazily when needed via `__toString()`.
 *
 * We cache the assembled string in `$assembled` after the first `__toString()` call.
 * Since this is an immutable object, the cache is always valid for the lifetime of
 * the instance.
 */
final class Uri implements \Stringable
{
    // NOTE: Not declared readonly so fromParts() can assign after construction.
    // The class is final — external mutation is impossible. Immutability is
    // enforced architecturally (all with* return new instances).
    private string $scheme   = '';
    private string $userInfo = '';
    private string $host     = '';
    private ?int   $port     = null;
    private string $path     = '';
    private string $query    = '';
    private string $fragment = '';

    /**
     * Cached assembled URI string. Populated lazily on first `__toString()` call.
     */
    private ?string $assembled = null;

    /**
     * Private parts-based factory used by all with* methods.
     * Bypasses parse_url() since we already have parsed components.
     */
    private static function fromParts(
        string $scheme,
        string $userInfo,
        string $host,
        ?int   $port,
        string $path,
        string $query,
        string $fragment,
    ): static {
        $instance           = new static();
        $instance->scheme   = $scheme;
        $instance->userInfo = $userInfo;
        $instance->host     = $host;
        $instance->port     = $port;
        $instance->path     = $path;
        $instance->query    = $query;
        $instance->fragment = $fragment;

        return $instance;
    }

    /**
     * Standard default ports per scheme. Used to omit the port from the authority
     * component when it matches the scheme's default (per RFC 3986 §3.2.3).
     */
    private const DEFAULT_PORTS = [
        'http'   => 80,
        'https'  => 443,
        'ftp'    => 21,
        'ftps'   => 990,
        'sftp'   => 22,
        'ws'     => 80,
        'wss'    => 443,
        'smtp'   => 25,
        'imap'   => 143,
        'pop3'   => 110,
    ];

    public function __construct(string $uri = '')
    {
        if ($uri === '') {
            return;
        }

        $parts = parse_url($uri);

        if ($parts === false) {
            throw new \InvalidArgumentException(
                sprintf('The URI "%s" is malformed and cannot be parsed.', $uri)
            );
        }

        $this->scheme   = isset($parts['scheme'])   ? strtolower($parts['scheme']) : '';
        $this->host     = isset($parts['host'])     ? strtolower($parts['host'])   : '';
        $this->port     = isset($parts['port'])     ? $parts['port']               : null;
        $this->path     = $parts['path']     ?? '';
        $this->query    = $parts['query']    ?? '';
        $this->fragment = $parts['fragment'] ?? '';

        // Combine user and password into the userinfo subcomponent
        $user     = $parts['user']     ?? '';
        $password = $parts['pass']     ?? '';
        $this->userInfo = $password !== '' ? "{$user}:{$password}" : $user;

        if ($this->port !== null) {
            $this->validatePort($this->port);
        }
    }

    // ─── Getters ──────────────────────────────────────────────────────────────

    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * Returns the authority component: [userinfo@]host[:port]
     * Returns an empty string if the host is not set.
     */
    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }

        $authority = $this->host;

        if ($this->userInfo !== '') {
            $authority = $this->userInfo . '@' . $authority;
        }

        if ($this->port !== null && !$this->isDefaultPort()) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    public function getUserInfo(): string
    {
        return $this->userInfo;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * Returns the port. Returns null if no port is set.
     * Does NOT return the default port for the scheme — use `getEffectivePort()` for that.
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * Returns the effective port: the explicitly set port, or the default port for the scheme.
     * Returns null if neither is known.
     */
    public function getEffectivePort(): ?int
    {
        if ($this->port !== null) {
            return $this->port;
        }

        return self::DEFAULT_PORTS[$this->scheme] ?? null;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    /**
     * Returns the query string parsed into a key-value array.
     * Handles multi-value keys (e.g., `tag[]=php&tag[]=http`).
     *
     * @return array<string, string|list<string>>
     */
    public function getQueryParams(): array
    {
        $params = [];
        parse_str($this->query, $params);

        return $params;
    }

    // ─── Immutable Builders (with* methods) ───────────────────────────────────

    public function withScheme(string $scheme): static
    {
        $normalized = strtolower($scheme);

        if ($normalized === $this->scheme) {
            return $this;
        }

        return static::fromParts($normalized, $this->userInfo, $this->host, $this->port, $this->path, $this->query, $this->fragment);
    }

    /**
     * Sets the user info component. Pass an empty string to remove authentication.
     */
    public function withUserInfo(string $user, ?string $password = null): static
    {
        $info = $password !== null && $password !== ''
            ? "{$user}:{$password}"
            : $user;

        if ($info === $this->userInfo) {
            return $this;
        }

        return static::fromParts($this->scheme, $info, $this->host, $this->port, $this->path, $this->query, $this->fragment);
    }

    public function withHost(string $host): static
    {
        $normalized = strtolower($host);

        if ($normalized === $this->host) {
            return $this;
        }

        return static::fromParts($this->scheme, $this->userInfo, $normalized, $this->port, $this->path, $this->query, $this->fragment);
    }

    public function withPort(?int $port): static
    {
        if ($port !== null) {
            $this->validatePort($port);
        }

        if ($port === $this->port) {
            return $this;
        }

        return static::fromParts($this->scheme, $this->userInfo, $this->host, $port, $this->path, $this->query, $this->fragment);
    }

    public function withPath(string $path): static
    {
        if ($path === $this->path) {
            return $this;
        }

        return static::fromParts($this->scheme, $this->userInfo, $this->host, $this->port, $path, $this->query, $this->fragment);
    }

    public function withQuery(string $query): static
    {
        $normalized = ltrim($query, '?');

        if ($normalized === $this->query) {
            return $this;
        }

        return static::fromParts($this->scheme, $this->userInfo, $this->host, $this->port, $this->path, $normalized, $this->fragment);
    }

    /**
     * Sets the query string from a key-value array.
     * Handles nested arrays and multi-value keys automatically via http_build_query().
     *
     * @param array<string, mixed> $params
     */
    public function withQueryParams(array $params): static
    {
        return $this->withQuery(http_build_query($params, encoding_type: PHP_QUERY_RFC3986));
    }

    /**
     * Merges additional query parameters into the existing query string.
     * Existing keys with the same name are overwritten.
     *
     * @param array<string, mixed> $params
     */
    public function withMergedQueryParams(array $params): static
    {
        return $this->withQueryParams(array_merge($this->getQueryParams(), $params));
    }

    /**
     * Removes one or more query parameters by name.
     *
     * @param string ...$keys The parameter names to remove.
     */
    public function withoutQueryParam(string ...$keys): static
    {
        $params = $this->getQueryParams();

        foreach ($keys as $key) {
            unset($params[$key]);
        }

        return $this->withQueryParams($params);
    }

    public function withFragment(string $fragment): static
    {
        $normalized = ltrim($fragment, '#');

        if ($normalized === $this->fragment) {
            return $this;
        }

        return static::fromParts($this->scheme, $this->userInfo, $this->host, $this->port, $this->path, $this->query, $normalized);
    }

    // ─── Utility Methods ──────────────────────────────────────────────────────

    /**
     * Returns true if this URI has the HTTPS scheme.
     */
    public function isHttps(): bool
    {
        return $this->scheme === 'https';
    }

    /**
     * Returns true if the port is set and it is the default for the current scheme.
     */
    public function isDefaultPort(): bool
    {
        return $this->port !== null
            && isset(self::DEFAULT_PORTS[$this->scheme])
            && self::DEFAULT_PORTS[$this->scheme] === $this->port;
    }

    /**
     * Returns true if the URI has a non-empty host component.
     */
    public function isAbsolute(): bool
    {
        return $this->host !== '';
    }

    /**
     * Resolves a relative reference URI against this base URI per RFC 3986 §5.
     */
    public function resolve(string|Uri $reference): static
    {
        $ref = $reference instanceof Uri ? $reference : new static((string) $reference);

        if ($ref->scheme !== '') {
            return $ref->withPath(self::removeDotSegments($ref->path));
        }

        if ($ref->getAuthority() !== '') {
            return $ref
                ->withScheme($this->scheme)
                ->withPath(self::removeDotSegments($ref->path));
        }

        if ($ref->path === '') {
            $path  = $this->path;
            $query = $ref->query !== '' ? $ref->query : $this->query;
        } else {
            if (str_starts_with($ref->path, '/')) {
                $path = self::removeDotSegments($ref->path);
            } else {
                // Merge paths
                $base  = $this->getAuthority() !== '' && $this->path === ''
                    ? '/'
                    : substr($this->path, 0, (int) strrpos($this->path, '/') + 1);

                $path = self::removeDotSegments($base . $ref->path);
            }

            $query = $ref->query;
        }

        return (new static())
            ->withScheme($this->scheme)
            ->withUserInfo(...explode(':', $this->userInfo, 2) + [1 => null])
            ->withHost($this->host)
            ->withPort($this->port)
            ->withPath($path)
            ->withQuery($query)
            ->withFragment($ref->fragment);
    }

    /**
     * Assembles the full URI string. Cached after first call.
     */
    public function __toString(): string
    {
        if ($this->assembled !== null) {
            return $this->assembled;
        }

        $uri = '';

        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }

        $authority = $this->getAuthority();

        if ($authority !== '' || $this->scheme === 'file') {
            $uri .= '//' . $authority;
        }

        $path = $this->path;

        if ($authority !== '' && $path !== '' && !str_starts_with($path, '/')) {
            // Path must begin with "/" if authority is present (RFC 3986 §3.3)
            $path = '/' . $path;
        }

        $uri .= $path;

        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }

        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $this->assembled = $uri;
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    private function invalidateCache(): static
    {
        $this->assembled = null;

        return $this;
    }

    private function validatePort(int $port): void
    {
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(
                sprintf('Port must be between 1 and 65535, got %d.', $port)
            );
        }
    }

    /**
     * Removes dot-segments ("." and "..") from a URI path per RFC 3986 §5.2.4.
     */
    private static function removeDotSegments(string $path): string
    {
        if (!str_contains($path, '.')) {
            return $path;
        }

        $output = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($output);
            } elseif ($segment !== '.') {
                $output[] = $segment;
            }
        }

        return implode('/', $output);
    }
}
