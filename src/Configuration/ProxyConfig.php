<?php

declare(strict_types=1);

namespace WireHttp\Configuration;

/**
 * ProxyConfig — HTTP/SOCKS Proxy Configuration DTO
 *
 * Configures outbound requests to route through an HTTP, HTTPS, or SOCKS proxy.
 * Supports per-host bypass rules and proxy authentication.
 */
final class ProxyConfig
{
    /**
     * @param string        $uri       The proxy server URI (e.g., "http://proxy.example.com:8080",
     *                                 "socks5://127.0.0.1:1080").
     * @param string|null   $username  Proxy authentication username (Basic auth).
     * @param string|null   $password  Proxy authentication password.
     * @param list<string>  $noProxy   List of hostnames/IP ranges to bypass the proxy.
     *                                 Supports wildcards: "*.example.com", "10.0.0.0/8".
     * @param bool          $tunneling For HTTPS requests: use CONNECT tunneling through the proxy.
     *                                 Set to false only for legacy HTTP proxies that don't support CONNECT.
     */
    public function __construct(
        public readonly string  $uri,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly array   $noProxy = [],
        public readonly bool    $tunneling = true,
    ) {
        if (!str_contains($uri, '://')) {
            throw new \InvalidArgumentException(
                "ProxyConfig URI must include a scheme (e.g., 'http://proxy:8080'). Got: '{$uri}'"
            );
        }
    }

    /**
     * Creates a simple HTTP proxy with optional credentials.
     */
    public static function http(string $host, int $port = 8080, ?string $user = null, ?string $pass = null): static
    {
        return new static(
            uri: "http://{$host}:{$port}",
            username: $user,
            password: $pass,
        );
    }

    /**
     * Creates a SOCKS5 proxy (commonly used with Tor or SSH tunnels).
     */
    public static function socks5(string $host, int $port = 1080, ?string $user = null, ?string $pass = null): static
    {
        return new static(
            uri: "socks5://{$host}:{$port}",
            username: $user,
            password: $pass,
        );
    }

    /**
     * Returns the proxy credentials in "username:password" format for cURL.
     * Returns null if no credentials are configured.
     */
    public function getCredentials(): ?string
    {
        if ($this->username === null) {
            return null;
        }

        return $this->username . ($this->password !== null ? ':' . $this->password : '');
    }

    /**
     * Returns true if the given hostname should bypass the proxy.
     * Checks against the noProxy list, supporting wildcard patterns.
     */
    public function shouldBypass(string $host): bool
    {
        foreach ($this->noProxy as $pattern) {
            $pattern = ltrim($pattern, '*.');
            if ($host === $pattern || str_ends_with($host, '.' . $pattern)) {
                return true;
            }
        }

        return false;
    }
}
