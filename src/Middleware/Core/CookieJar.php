<?php

declare(strict_types=1);

namespace WireHttp\Middleware\Core;

use WireHttp\Http\Uri;

/**
 * CookieJar — RFC 6265-Compliant HTTP Cookie Store
 *
 * The CookieJar stores cookies received from servers and provides them back
 * to the client on subsequent requests to matching domains and paths.
 *
 * RFC 6265 Compliance:
 * --------------------
 *  - Domain matching: "example.com" cookie is sent to "example.com" and "sub.example.com".
 *  - Path matching: "/api" cookie is sent to "/api/users" but NOT to "/other".
 *  - Secure flag: "Secure" cookies are only sent over HTTPS.
 *  - HttpOnly flag: "HttpOnly" cookies are stored but flagged (not accessible to JS).
 *  - Expiry: Expired cookies are not stored and are automatically purged on access.
 *  - SameSite: Tracked but not enforced (client-side concern).
 *
 * Session vs Persistent Cookies:
 * --------------------------------
 *  - Session cookies (no Max-Age or Expires) are purged when `clearSession()` is called.
 *  - Persistent cookies (with Max-Age or Expires) survive session clears.
 *
 * Thread / Fiber Safety:
 * ----------------------
 * The CookieJar is NOT thread-safe by design. Each Client instance should have
 * its own CookieJar. Do not share a single CookieJar across concurrent requests
 * in different Fibers without external synchronization.
 */
final class CookieJar
{
    /**
     * The internal cookie store.
     * Structure: domain → path → name → Cookie
     *
     * @var array<string, array<string, array<string, Cookie>>>
     */
    private array $cookies = [];

    // ─── Writing Cookies (from server response) ───────────────────────────────

    /**
     * Parses all Set-Cookie headers from a response and stores the cookies.
     *
     * @param list<string> $setCookieHeaders The raw Set-Cookie header values.
     * @param Uri          $requestUri       The URI of the request that produced these cookies.
     */
    public function storeCookiesFromResponse(array $setCookieHeaders, Uri $requestUri): void
    {
        foreach ($setCookieHeaders as $headerValue) {
            $cookie = $this->parseSetCookieHeader($headerValue, $requestUri);

            if ($cookie !== null) {
                $this->store($cookie);
            }
        }
    }

    /**
     * Stores a single Cookie object.
     */
    public function store(Cookie $cookie): void
    {
        // Reject expired cookies immediately — no point storing them
        if ($cookie->isExpired()) {
            $this->remove($cookie->domain, $cookie->path, $cookie->name);

            return;
        }

        $this->cookies[$cookie->domain][$cookie->path][$cookie->name] = $cookie;
    }

    /**
     * Removes a specific cookie by domain, path, and name.
     */
    public function remove(string $domain, string $path, string $name): void
    {
        unset($this->cookies[$domain][$path][$name]);

        // Clean up empty buckets to prevent memory bloat
        if (isset($this->cookies[$domain][$path]) && empty($this->cookies[$domain][$path])) {
            unset($this->cookies[$domain][$path]);
        }

        if (isset($this->cookies[$domain]) && empty($this->cookies[$domain])) {
            unset($this->cookies[$domain]);
        }
    }

    /**
     * Clears all session cookies (cookies without a Max-Age or Expires attribute).
     * Called when the "browser session" ends. Persistent cookies are kept.
     */
    public function clearSession(): void
    {
        foreach ($this->cookies as $domain => &$paths) {
            foreach ($paths as $path => &$names) {
                foreach ($names as $name => $cookie) {
                    if (!$cookie->isPersistent()) {
                        unset($names[$name]);
                    }
                }

                if (empty($names)) {
                    unset($paths[$path]);
                }
            }

            if (empty($paths)) {
                unset($this->cookies[$domain]);
            }
        }
    }

    /**
     * Clears ALL cookies (both session and persistent).
     */
    public function clear(): void
    {
        $this->cookies = [];
    }

    // ─── Reading Cookies (for outgoing requests) ──────────────────────────────

    /**
     * Returns the Cookie header value for an outgoing request to the given URI.
     * Applies RFC 6265 domain matching, path matching, Secure flag checking,
     * and expiry filtering.
     *
     * Returns an empty string if no cookies match.
     */
    public function getCookieHeaderForRequest(Uri $uri): string
    {
        $isSecure = $uri->getScheme() === 'https';
        $host     = strtolower($uri->getHost());
        $path     = $uri->getPath() ?: '/';

        $matched  = [];
        $toDelete = [];

        foreach ($this->cookies as $domain => $paths) {
            // RFC 6265 §5.1.3 domain matching
            if (!$this->domainMatches($host, $domain)) {
                continue;
            }

            foreach ($paths as $cookiePath => $names) {
                // RFC 6265 §5.1.4 path matching
                if (!$this->pathMatches($path, $cookiePath)) {
                    continue;
                }

                foreach ($names as $name => $cookie) {
                    // Purge expired cookies lazily
                    if ($cookie->isExpired()) {
                        $toDelete[] = [$domain, $cookiePath, $name];

                        continue;
                    }

                    // Secure cookies only sent over HTTPS
                    if ($cookie->secure && !$isSecure) {
                        continue;
                    }

                    $matched[] = $cookie;
                }
            }
        }

        // Lazy purge of expired cookies discovered during matching
        foreach ($toDelete as [$d, $p, $n]) {
            $this->remove($d, $p, $n);
        }

        if (empty($matched)) {
            return '';
        }

        // Sort by path length (longer paths have higher specificity) per RFC 6265 §5.4
        usort($matched, static function (Cookie $a, Cookie $b): int {
            return strlen($b->path) <=> strlen($a->path);
        });

        return implode('; ', array_map(
            static fn(Cookie $c) => $c->name . '=' . $c->value,
            $matched
        ));
    }

    /**
     * Returns all stored cookies as a flat array.
     *
     * @return list<Cookie>
     */
    public function all(): array
    {
        $all = [];

        foreach ($this->cookies as $paths) {
            foreach ($paths as $names) {
                foreach ($names as $cookie) {
                    $all[] = $cookie;
                }
            }
        }

        return $all;
    }

    /**
     * Returns the total number of cookies stored.
     */
    public function count(): int
    {
        return count($this->all());
    }

    // ─── Parsing ─────────────────────────────────────────────────────────────

    /**
     * Parses a raw Set-Cookie header value into a Cookie object.
     * Returns null if the cookie value is unparseable or should be rejected.
     */
    private function parseSetCookieHeader(string $header, Uri $requestUri): ?Cookie
    {
        $parts = array_map('trim', explode(';', $header));
        $nameValue = array_shift($parts);

        if ($nameValue === null || !str_contains($nameValue, '=')) {
            return null; // Invalid format
        }

        [$name, $value] = explode('=', $nameValue, 2);
        $name  = trim($name);
        $value = trim($value);

        if ($name === '') {
            return null;
        }

        // Defaults from the request URI
        $domain  = strtolower($requestUri->getHost());
        $path    = $this->defaultCookiePath($requestUri->getPath());
        $expires = null;
        $maxAge  = null;
        $secure  = false;
        $httpOnly = false;
        $sameSite = null;

        foreach ($parts as $attribute) {
            $attribute = trim($attribute);

            if (strcasecmp($attribute, 'secure') === 0) {
                $secure = true;

                continue;
            }

            if (strcasecmp($attribute, 'httponly') === 0) {
                $httpOnly = true;

                continue;
            }

            if (!str_contains($attribute, '=')) {
                continue;
            }

            [$attrName, $attrValue] = explode('=', $attribute, 2);
            $attrName  = strtolower(trim($attrName));
            $attrValue = trim($attrValue);

            match ($attrName) {
                'domain'   => $domain   = ltrim(strtolower($attrValue), '.'),
                'path'     => $path     = $attrValue ?: '/',
                'samesite' => $sameSite = $attrValue,
                'max-age'  => $maxAge   = (int) $attrValue,
                'expires'  => $expires  = strtotime($attrValue) ?: null,
                default    => null,
            };
        }

        // Max-Age takes precedence over Expires per RFC 6265
        $expiresAt = null;

        if ($maxAge !== null) {
            $expiresAt = time() + $maxAge;
        } elseif ($expires !== null) {
            $expiresAt = $expires;
        }

        return new Cookie(
            name: $name,
            value: $value,
            domain: $domain,
            path: $path,
            expiresAt: $expiresAt,
            secure: $secure,
            httpOnly: $httpOnly,
            sameSite: $sameSite,
        );
    }

    /**
     * Computes the default cookie path from a request URI path per RFC 6265 §5.1.4.
     */
    private function defaultCookiePath(string $uriPath): string
    {
        if ($uriPath === '' || $uriPath === '/') {
            return '/';
        }

        $lastSlash = strrpos($uriPath, '/');

        if ($lastSlash === 0) {
            return '/';
        }

        return substr($uriPath, 0, $lastSlash);
    }

    /**
     * RFC 6265 §5.1.3 domain-match algorithm.
     * Returns true if $cookieDomain matches $requestHost.
     */
    private function domainMatches(string $requestHost, string $cookieDomain): bool
    {
        // Exact match
        if ($requestHost === $cookieDomain) {
            return true;
        }

        // Subdomain match: request is "sub.example.com", cookie domain is "example.com"
        return str_ends_with($requestHost, '.' . $cookieDomain);
    }

    /**
     * RFC 6265 §5.1.4 path-match algorithm.
     * Returns true if $cookiePath is a prefix of $requestPath.
     */
    private function pathMatches(string $requestPath, string $cookiePath): bool
    {
        if ($requestPath === $cookiePath) {
            return true;
        }

        if (str_starts_with($requestPath, $cookiePath)) {
            return str_ends_with($cookiePath, '/')
                || str_starts_with(substr($requestPath, strlen($cookiePath)), '/');
        }

        return false;
    }
}

/**
 * Cookie — RFC 6265 Cookie Value Object
 *
 * @internal Used only by CookieJar.
 */
final class Cookie
{
    public function __construct(
        public readonly string  $name,
        public readonly string  $value,
        public readonly string  $domain,
        public readonly string  $path = '/',
        public readonly ?int    $expiresAt = null,   // Unix timestamp or null (session cookie)
        public readonly bool    $secure = false,
        public readonly bool    $httpOnly = false,
        public readonly ?string $sameSite = null,
    ) {
    }

    /**
     * Returns true if this cookie has expired.
     */
    public function isExpired(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= time();
    }

    /**
     * Returns true if this is a persistent cookie (has a defined expiry).
     * Session cookies (no expiry) return false.
     */
    public function isPersistent(): bool
    {
        return $this->expiresAt !== null;
    }

    /**
     * Returns the remaining lifetime in seconds, or null for session cookies.
     * Returns 0 if already expired.
     */
    public function remainingLifetime(): ?int
    {
        if ($this->expiresAt === null) {
            return null;
        }

        return max(0, $this->expiresAt - time());
    }
}
