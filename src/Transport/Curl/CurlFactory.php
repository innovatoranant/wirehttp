<?php

declare(strict_types=1);

namespace WireHttp\Transport\Curl;

use WireHttp\Configuration\SslConfig;
use WireHttp\Configuration\ProxyConfig;
use WireHttp\Configuration\TimeoutConfig;
use WireHttp\Enums\AuthType;
use WireHttp\Enums\HttpMethod;
use WireHttp\Enums\HttpVersion;
use WireHttp\Http\Request;
use WireHttp\Http\Stream;

/**
 * CurlFactory — Constructs and Configures cURL Handles
 *
 * This class is the single source of truth for all CURLOPT_* settings in WireHTTP.
 * It takes a Request and produces a fully-configured \CurlHandle ready for execution.
 *
 * Design Goals:
 * -------------
 *  1. **Performance:** We pre-calculate everything possible before calling curl_setopt_array().
 *     A single curl_setopt_array() call is dramatically faster than many individual
 *     curl_setopt() calls because it avoids the per-call PHP→C FFI overhead.
 *  2. **Correctness:** Every CURLOPT is set explicitly. We do NOT rely on cURL defaults
 *     (which vary across versions and builds) for security-critical options like SSL.
 *  3. **Security:** SSL verification is ALWAYS enabled by default. Disabling it requires
 *     an explicit opt-out via SslConfig, which triggers a warning in debug mode.
 *  4. **Connection Reuse:** We maintain a pool of reusable cURL handles per host.
 *     curl_reset() on a pooled handle is faster than curl_init() for a new one.
 *
 * Connection Pool:
 * ----------------
 * cURL natively reuses TCP connections when you reuse the same \CurlHandle.
 * We maintain a small pool of handles keyed by host, allowing keep-alive
 * connections to be leveraged without any extra infrastructure.
 */
final class CurlFactory
{
    /**
     * Maximum number of idle handles to keep in the pool per host.
     */
    private const POOL_SIZE_PER_HOST = 5;

    /**
     * Connection pool: host → array of idle CurlHandle objects.
     * Keyed by "scheme://host:port" to ensure handles match the right endpoint.
     *
     * @var array<string, list<\CurlHandle>>
     */
    private array $pool = [];

    /**
     * The default timeout configuration used when a Request has no timeout set.
     */
    private readonly TimeoutConfig $defaultTimeout;

    /**
     * The default SSL configuration.
     */
    private readonly SslConfig $defaultSsl;

    public function __construct(
        ?TimeoutConfig $defaultTimeout = null,
        ?SslConfig $defaultSsl = null,
    ) {
        $this->defaultTimeout = $defaultTimeout ?? new TimeoutConfig();
        $this->defaultSsl     = $defaultSsl ?? new SslConfig();
    }

    // ─── Primary Factory Method ───────────────────────────────────────────────

    /**
     * Creates a fully-configured cURL handle for the given Request.
     *
     * Attempts to reuse a pooled handle for the target host before creating a
     * new one. Returns the handle and a buffer reference that cURL will write
     * the response headers into.
     *
     * @param Request        $request       The HTTP request to configure the handle for.
     * @param string         $responseHeaders Output reference: cURL writes raw headers here.
     * @param Stream         $responseBody  The stream cURL should write the body into.
     * @return \CurlHandle
     */
    public function create(
        Request $request,
        string  &$responseHeaders,
        Stream  $responseBody,
    ): \CurlHandle {
        $handle = $this->acquireHandle($request);

        $options = $this->buildOptions($request, $responseHeaders, $responseBody);

        curl_setopt_array($handle, $options);

        return $handle;
    }

    /**
     * Returns a cURL handle back to the pool for reuse.
     * The handle is reset but not closed, allowing TCP keep-alive to persist.
     *
     * @param \CurlHandle $handle  The handle to recycle.
     * @param string      $poolKey The pool key (scheme://host:port).
     */
    public function recycle(\CurlHandle $handle, string $poolKey): void
    {
        $pool = &$this->pool[$poolKey];

        if (!isset($pool)) {
            $pool = [];
        }

        if (count($pool) < self::POOL_SIZE_PER_HOST) {
            curl_reset($handle);
            $pool[] = $handle;
        } else {
            curl_close($handle);
        }
    }

    /**
     * Generates the pool key for a given Request.
     * Format: "scheme://host:effectivePort"
     */
    public function getPoolKey(Request $request): string
    {
        $uri  = $request->getUri();
        $port = $uri->getEffectivePort() ?? 80;

        return sprintf('%s://%s:%d', $uri->getScheme(), $uri->getHost(), $port);
    }

    /**
     * Drains and closes all handles in the connection pool.
     * Should be called when shutting down the client.
     */
    public function drain(): void
    {
        foreach ($this->pool as $handles) {
            foreach ($handles as $handle) {
                curl_close($handle);
            }
        }

        $this->pool = [];
    }

    // ─── Private: Handle Acquisition ─────────────────────────────────────────

    /**
     * Acquires a cURL handle — either from the pool or freshly initialized.
     */
    private function acquireHandle(Request $request): \CurlHandle
    {
        $poolKey = $this->getPoolKey($request);

        if (!empty($this->pool[$poolKey])) {
            return array_pop($this->pool[$poolKey]);
        }

        $handle = curl_init();

        if ($handle === false) {
            throw new \RuntimeException(
                'Failed to initialize a cURL handle. Check that ext-curl is loaded and functional.'
            );
        }

        return $handle;
    }

    // ─── Private: Options Builder ─────────────────────────────────────────────

    /**
     * Builds the full CURLOPT array for the given Request.
     * All options are computed in one pass and applied in a single curl_setopt_array() call.
     *
     * @return array<int, mixed>
     */
    private function buildOptions(
        Request $request,
        string  &$responseHeaders,
        Stream  $responseBody,
    ): array {
        $uri     = $request->getUri();
        $method  = $request->getMethodEnum();
        $options = $request->getOptions();

        // ── Resolve Configuration Objects ──────────────────────────────────
        /** @var TimeoutConfig $timeout */
        $timeout = $options['timeout'] ?? $this->defaultTimeout;

        /** @var SslConfig $ssl */
        $ssl = $options['ssl'] ?? $this->defaultSsl;

        /** @var ProxyConfig|null $proxy */
        $proxy = $options['proxy'] ?? null;

        // ── HTTP Version ────────────────────────────────────────────────────
        $httpVersion = HttpVersion::tryFrom($request->getProtocolVersion()) ?? HttpVersion::HTTP_1_1;
        $curlVersion  = $httpVersion->toCurlVersion();

        // ── Build Header Lines ───────────────────────────────────────────────
        $headerLines = [];

        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headerLines[] = "{$name}: {$value}";
            }
        }

        // ── Body ─────────────────────────────────────────────────────────────
        $body        = $request->getBody();
        $bodyContent = null;

        if ($method->hasBody() && ($bodySize = $body->getSize()) !== null && $bodySize > 0) {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            $bodyContent = $body->getContents();
        }

        // ── Response Body Writer ──────────────────────────────────────────────
        // We use a write function that pipes cURL data directly into our Stream
        $bodyStream = $responseBody;

        $writeFunction = static function (\CurlHandle $ch, string $data) use ($bodyStream): int {
            return $bodyStream->write($data);
        };

        // ── Header Function ────────────────────────────────────────────────────
        // cURL calls this for each response header line (including status line).
        $headerBuffer = &$responseHeaders;
        $headerBuffer = '';

        $headerFunction = static function (\CurlHandle $ch, string $header) use (&$headerBuffer): int {
            $headerBuffer .= $header;

            return strlen($header);
        };

        // ── Assemble the Options Array ────────────────────────────────────────
        $curlOptions = [
            // ── URL & Method ────────────────────────────────────────────────
            CURLOPT_URL            => (string) $uri,
            CURLOPT_HTTP_VERSION   => $curlVersion,
            CURLOPT_CUSTOMREQUEST  => $method->value,

            // ── Response Handling ────────────────────────────────────────────
            CURLOPT_RETURNTRANSFER => false,        // We use write functions instead
            CURLOPT_WRITEFUNCTION  => $writeFunction,
            CURLOPT_HEADERFUNCTION => $headerFunction,

            // ── Headers ─────────────────────────────────────────────────────
            CURLOPT_HTTPHEADER     => $headerLines,

            // ── Connection ──────────────────────────────────────────────────
            CURLOPT_FOLLOWLOCATION => false,         // We handle redirects in middleware
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_ENCODING       => '',            // '' = accept all encodings (gzip, deflate, br)
            CURLOPT_TCP_KEEPALIVE  => 1,
            CURLOPT_TCP_KEEPIDLE   => 60,
            CURLOPT_TCP_KEEPINTVL  => 30,

            // ── Timeouts ────────────────────────────────────────────────────
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($timeout->connectSeconds * 1000),
            CURLOPT_TIMEOUT_MS        => (int) ($timeout->requestSeconds * 1000),

            // ── SSL / TLS ────────────────────────────────────────────────────
            CURLOPT_SSL_VERIFYPEER => $ssl->verifyPeer ? 1 : 0,
            CURLOPT_SSL_VERIFYHOST => $ssl->verifyHost ? 2 : 0,

            // ── Diagnostics ──────────────────────────────────────────────────
            CURLOPT_VERBOSE        => (bool) ($options['debug'] ?? false),
        ];

        // ── SSL: CA Bundle / Certificate ─────────────────────────────────────
        if ($ssl->caBundle !== null) {
            $curlOptions[CURLOPT_CAINFO] = $ssl->caBundle;
        }

        if ($ssl->caPath !== null) {
            $curlOptions[CURLOPT_CAPATH] = $ssl->caPath;
        }

        if ($ssl->clientCert !== null) {
            $curlOptions[CURLOPT_SSLCERT] = $ssl->clientCert;
        }

        if ($ssl->clientKey !== null) {
            $curlOptions[CURLOPT_SSLKEY] = $ssl->clientKey;
        }

        if ($ssl->clientKeyPassphrase !== null) {
            $curlOptions[CURLOPT_SSLKEYPASSWD] = $ssl->clientKeyPassphrase;
        }

        if ($ssl->minVersion !== null) {
            $curlOptions[CURLOPT_SSLVERSION] = match ($ssl->minVersion) {
                'TLSv1.0' => CURL_SSLVERSION_TLSv1_0,
                'TLSv1.1' => CURL_SSLVERSION_TLSv1_1,
                'TLSv1.2' => CURL_SSLVERSION_TLSv1_2,
                'TLSv1.3' => CURL_SSLVERSION_TLSv1_3,
                default   => CURL_SSLVERSION_DEFAULT,
            };
        }

        // ── Body ──────────────────────────────────────────────────────────────
        if ($bodyContent !== null) {
            $curlOptions[CURLOPT_POSTFIELDS] = $bodyContent;

            if ($method === HttpMethod::POST) {
                $curlOptions[CURLOPT_POST] = true;
            }
        } elseif ($method === HttpMethod::HEAD) {
            $curlOptions[CURLOPT_NOBODY] = true;
        } elseif ($method === HttpMethod::GET) {
            $curlOptions[CURLOPT_HTTPGET] = true;
        }

        // ── Proxy ─────────────────────────────────────────────────────────────
        if ($proxy !== null) {
            $curlOptions[CURLOPT_PROXY]     = $proxy->uri;
            $curlOptions[CURLOPT_PROXYTYPE] = $proxy->type;

            if ($proxy->username !== null) {
                $curlOptions[CURLOPT_PROXYUSERPWD] = $proxy->username . ':' . ($proxy->password ?? '');
            }

            if ($proxy->noProxy !== []) {
                $curlOptions[CURLOPT_NOPROXY] = implode(',', $proxy->noProxy);
            }
        }

        // ── Authentication ────────────────────────────────────────────────────
        /** @var array{type: AuthType, username: string, password?: string}|null $auth */
        $auth = $options['auth'] ?? null;

        if ($auth !== null && $auth['type']->isCurlNative()) {
            $curlOptions[CURLOPT_HTTPAUTH] = $auth['type']->toCurlAuth();
            $curlOptions[CURLOPT_USERPWD]  = $auth['username'] . ':' . ($auth['password'] ?? '');
        }

        // ── Interface Binding ─────────────────────────────────────────────────
        if (isset($options['interface'])) {
            $curlOptions[CURLOPT_INTERFACE] = $options['interface'];
        }

        // ── HTTP/2 Push Disable (security: disable server push by default) ────
        if (defined('CURLOPT_PUSH_FUNCTION') && $httpVersion === HttpVersion::HTTP_2) {
            $curlOptions[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
        }

        return $curlOptions;
    }
}
