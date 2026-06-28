<?php

declare(strict_types=1);

namespace WireHttp\Configuration;

use WireHttp\Enums\HttpVersion;
use WireHttp\Middleware\Core\CookieJar;

/**
 * ClientConfig — The Master Configuration DTO for WireHTTP Client
 *
 * All client-wide settings live here as immutable readonly properties.
 * Changes produce new instances via `with*()` clone methods, preserving
 * the original config for re-use (critical for per-request overrides).
 *
 * Usage:
 *   $config = ClientConfig::create()
 *       ->withBaseUri('https://api.example.com')
 *       ->withTimeout(TimeoutConfig::fast())
 *       ->withSsl(SslConfig::tls13Only())
 *       ->withDefaultHeader('Accept', 'application/json')
 *       ->withFollowRedirects(true, maxRedirects: 10)
 *       ->withRetry(maxAttempts: 3)
 *       ->withCookies(true);
 *
 *   Wire::configure($config);
 */
final class ClientConfig
{
    private function __construct(
        /** Base URI prepended to all relative request URIs. */
        public readonly ?string       $baseUri = null,

        /** Timeout settings for connect and full request. */
        public readonly TimeoutConfig $timeout = new TimeoutConfig(),

        /** SSL/TLS verification settings. */
        public readonly SslConfig     $ssl = new SslConfig(),

        /** Optional outbound proxy configuration. */
        public readonly ?ProxyConfig  $proxy = null,

        /** Default headers sent with every request (e.g., Accept, User-Agent). */
        public readonly array         $defaultHeaders = [],

        /** Maximum number of redirects to follow. 0 = disable redirect following. */
        public readonly int           $maxRedirects = 10,

        /** Whether to follow 3xx redirect responses. */
        public readonly bool          $followRedirects = true,

        /** Whether to automatically manage cookies across requests. */
        public readonly bool          $manageCookies = false,

        /** A shared CookieJar instance (used when $manageCookies = true). */
        public readonly ?CookieJar    $cookieJar = null,

        /** If true, write detailed cURL debug output to STDERR. */
        public readonly bool          $debug = false,

        /** HTTP protocol version preference. */
        public readonly HttpVersion   $httpVersion = HttpVersion::HTTP_1_1,

        /** User-Agent string sent with every request. */
        public readonly string        $userAgent = 'WireHTTP/1.0',

        /**
         * Basic auth credentials as ['username', 'password'].
         * @var array{0: string, 1: string}|null
         */
        public readonly ?array        $basicAuth = null,

        /** Max retries on transient failure (0 = no retry). */
        public readonly int           $maxRetries = 0,

        /** Base delay in seconds for exponential backoff on retries. */
        public readonly float         $retryDelaySeconds = 0.5,

        /**
         * Max delay cap in seconds for retry backoff.
         */
        public readonly float         $maxRetryDelaySeconds = 30.0,

        /**
         * Retry on these HTTP status codes (in addition to network errors).
         * @var list<int>
         */
        public readonly array         $retryOnStatusCodes = [429, 500, 502, 503, 504],

        /**
         * Additional arbitrary options (for transport-specific settings).
         * @var array<string, mixed>
         */
        public readonly array         $options = [],
    ) {
    }

    /**
     * Creates a new ClientConfig with all defaults.
     */
    public static function create(): static
    {
        return new static();
    }

    // ─── Immutable Builders ───────────────────────────────────────────────────

    public function withBaseUri(string $baseUri): static
    {
        return $this->cloneWith('baseUri', rtrim($baseUri, '/'));
    }

    public function withTimeout(TimeoutConfig $timeout): static
    {
        return $this->cloneWith('timeout', $timeout);
    }

    public function withTimeoutSeconds(float $seconds): static
    {
        return $this->withTimeout(TimeoutConfig::of($seconds));
    }

    public function withSsl(SslConfig $ssl): static
    {
        return $this->cloneWith('ssl', $ssl);
    }

    public function withProxy(ProxyConfig $proxy): static
    {
        return $this->cloneWith('proxy', $proxy);
    }

    public function withDefaultHeader(string $name, string $value): static
    {
        $headers        = $this->defaultHeaders;
        $headers[$name] = $value;

        return $this->cloneWith('defaultHeaders', $headers);
    }

    /** @param array<string, string> $headers */
    public function withDefaultHeaders(array $headers): static
    {
        return $this->cloneWith('defaultHeaders', array_merge($this->defaultHeaders, $headers));
    }

    public function withFollowRedirects(bool $follow = true, int $maxRedirects = 10): static
    {
        return $this
            ->cloneWith('followRedirects', $follow)
            ->cloneWith('maxRedirects', $maxRedirects);
    }

    public function withCookies(bool $manage = true, ?CookieJar $jar = null): static
    {
        return $this
            ->cloneWith('manageCookies', $manage)
            ->cloneWith('cookieJar', $jar ?? ($manage ? new CookieJar() : null));
    }

    public function withDebug(bool $debug = true): static
    {
        return $this->cloneWith('debug', $debug);
    }

    public function withHttpVersion(HttpVersion $version): static
    {
        return $this->cloneWith('httpVersion', $version);
    }

    public function withUserAgent(string $userAgent): static
    {
        return $this->cloneWith('userAgent', $userAgent);
    }

    /**
     * @param array{0: string, 1: string} $credentials [username, password]
     */
    public function withBasicAuth(string $username, string $password): static
    {
        return $this->cloneWith('basicAuth', [$username, $password]);
    }

    public function withRetry(
        int   $maxAttempts = 3,
        float $baseDelaySeconds = 0.5,
        float $maxDelaySeconds = 30.0,
        array $retryOnStatusCodes = [429, 500, 502, 503, 504],
    ): static {
        return $this
            ->cloneWith('maxRetries', $maxAttempts)
            ->cloneWith('retryDelaySeconds', $baseDelaySeconds)
            ->cloneWith('maxRetryDelaySeconds', $maxDelaySeconds)
            ->cloneWith('retryOnStatusCodes', $retryOnStatusCodes);
    }

    public function withOption(string $key, mixed $value): static
    {
        $options       = $this->options;
        $options[$key] = $value;

        return $this->cloneWith('options', $options);
    }

    // ─── Private ─────────────────────────────────────────────────────────────

    private function cloneWith(string $property, mixed $value): static
    {
        $clone            = clone $this;
        // We bypass readonly by constructing a new instance (clone + reinit)
        return new static(
            baseUri: $property === 'baseUri' ? $value : $this->baseUri,
            timeout: $property === 'timeout' ? $value : $this->timeout,
            ssl: $property === 'ssl' ? $value : $this->ssl,
            proxy: $property === 'proxy' ? $value : $this->proxy,
            defaultHeaders: $property === 'defaultHeaders' ? $value : $this->defaultHeaders,
            maxRedirects: $property === 'maxRedirects' ? $value : $this->maxRedirects,
            followRedirects: $property === 'followRedirects' ? $value : $this->followRedirects,
            manageCookies: $property === 'manageCookies' ? $value : $this->manageCookies,
            cookieJar: $property === 'cookieJar' ? $value : $this->cookieJar,
            debug: $property === 'debug' ? $value : $this->debug,
            httpVersion: $property === 'httpVersion' ? $value : $this->httpVersion,
            userAgent: $property === 'userAgent' ? $value : $this->userAgent,
            basicAuth: $property === 'basicAuth' ? $value : $this->basicAuth,
            maxRetries: $property === 'maxRetries' ? $value : $this->maxRetries,
            retryDelaySeconds: $property === 'retryDelaySeconds' ? $value : $this->retryDelaySeconds,
            maxRetryDelaySeconds: $property === 'maxRetryDelaySeconds' ? $value : $this->maxRetryDelaySeconds,
            retryOnStatusCodes: $property === 'retryOnStatusCodes' ? $value : $this->retryOnStatusCodes,
            options: $property === 'options' ? $value : $this->options,
        );
    }
}
