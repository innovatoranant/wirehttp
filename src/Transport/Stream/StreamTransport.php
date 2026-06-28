<?php

declare(strict_types=1);

namespace WireHttp\Transport\Stream;

use WireHttp\Async\Future;
use WireHttp\Exceptions\NetworkException;
use WireHttp\Exceptions\TimeoutException;
use WireHttp\Http\Request;
use WireHttp\Http\Response;
use WireHttp\Http\Stream;
use WireHttp\Transport\TransportInterface;

/**
 * StreamTransport — PHP Native Stream Wrapper Fallback Transport
 *
 * This transport is used when cURL is NOT available in the PHP environment
 * (e.g., containerized environments where ext-curl is stripped).
 *
 * It uses PHP's native `fopen()` with `http://` and `https://` stream wrappers,
 * which are part of PHP's core and require no extensions — only `allow_url_fopen`
 * must be enabled in php.ini.
 *
 * Limitations vs CurlTransport:
 * ------------------------------
 *  - HTTP/1.1 only. No HTTP/2 or HTTP/3 support.
 *  - No connection pooling or keep-alive support.
 *  - More limited SSL configuration options.
 *  - No progress callbacks or upload streaming.
 *  - No proxy authentication (only basic proxy via stream context).
 *  - `sendAsync()` is FAKE async — it wraps `send()` in a resolved Future.
 *    For true concurrency, use CurlMultiHandler.
 *
 * Despite these limitations, StreamTransport covers the vast majority of
 * basic HTTP use-cases and ensures WireHTTP "just works" everywhere.
 *
 * Availability Check:
 * -------------------
 * `isAvailable()` returns false if `allow_url_fopen` is off. In that case,
 * the Client falls back to throwing a clear configuration error.
 */
final class StreamTransport implements TransportInterface
{
    /**
     * The chunk size used when reading the response body from the stream.
     */
    private const READ_CHUNK_SIZE = 8192; // 8KB chunks

    // ─── TransportInterface ───────────────────────────────────────────────────

    /**
     * Sends the request using PHP's native HTTP stream wrapper.
     *
     * @throws NetworkException  On connection failure or invalid response.
     * @throws TimeoutException  On timeout (via stream context timeout option).
     */
    public function send(Request $request): Response
    {
        $context = $this->buildStreamContext($request);
        $uri     = (string) $request->getUri();

        // Suppress the PHP warning that fopen() emits on failure —
        // we handle errors via the return value and error_get_last().
        set_error_handler(null);
        $resource = @fopen($uri, 'rb', false, $context);
        restore_error_handler();

        if ($resource === false) {
            $lastError = error_get_last();
            $message   = $lastError['message'] ?? 'Unknown error opening stream';

            // Distinguish timeout from general connection failures
            if (str_contains(strtolower($message), 'timed out')) {
                throw new TimeoutException(
                    message: sprintf('StreamTransport request timed out: %s', $message),
                    request: $request,
                );
            }

            throw new NetworkException(
                message: sprintf('StreamTransport failed to connect to "%s": %s', $uri, $message),
                request: $request,
            );
        }

        try {
            return $this->buildResponse($resource, $request);
        } finally {
            // fclose() is also called inside buildResponse once headers are parsed,
            // but we call it here as a safety net in case an exception is thrown.
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    /**
     * StreamTransport does not support true async — wraps send() in a resolved Future.
     * For real concurrency, use CurlMultiHandler.
     *
     * @return Future<Response>
     */
    public function sendAsync(Request $request): Future
    {
        try {
            return Future::resolved($this->send($request));
        } catch (\Throwable $e) {
            return Future::rejected($e);
        }
    }

    /**
     * Returns true if PHP's allow_url_fopen ini setting is enabled.
     */
    public function isAvailable(): bool
    {
        return (bool) ini_get('allow_url_fopen');
    }

    /**
     * Returns a human-readable transport identifier.
     */
    public function name(): string
    {
        return sprintf('stream/php%d.%d', PHP_MAJOR_VERSION, PHP_MINOR_VERSION);
    }

    // ─── Private: Stream Context Builder ─────────────────────────────────────

    /**
     * Builds a PHP stream context from the Request's options and headers.
     * The stream context controls how PHP's HTTP wrapper behaves.
     *
     * @return resource A PHP stream context resource.
     */
    private function buildStreamContext(Request $request): mixed
    {
        $method  = $request->getMethod();
        $options = $request->getOptions();

        // Build raw header string
        $headerLines = [];

        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $headerLines[] = "{$name}: {$value}";
            }
        }

        $httpOptions = [
            'method'           => $method,
            'header'           => implode("\r\n", $headerLines),
            'protocol_version' => '1.1',
            'follow_location'  => 0,    // We handle redirects ourselves
            'max_redirects'    => 0,
            'ignore_errors'    => true, // Don't throw PHP warning on 4xx/5xx
        ];

        // Timeout
        $timeoutConfig = $options['timeout'] ?? null;

        if ($timeoutConfig !== null) {
            $httpOptions['timeout'] = $timeoutConfig->requestSeconds;
        }

        // Body
        $body = $request->getBody();

        if ($body->getSize() !== null && $body->getSize() > 0) {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            $httpOptions['content'] = $body->getContents();
        }

        // SSL options
        $sslConfig    = $options['ssl'] ?? null;
        $sslOptions   = [];

        if ($sslConfig !== null) {
            $sslOptions['verify_peer']       = $sslConfig->verifyPeer;
            $sslOptions['verify_peer_name']  = $sslConfig->verifyHost;

            if ($sslConfig->caBundle !== null) {
                $sslOptions['cafile'] = $sslConfig->caBundle;
            }

            if ($sslConfig->caPath !== null) {
                $sslOptions['capath'] = $sslConfig->caPath;
            }

            if ($sslConfig->clientCert !== null) {
                $sslOptions['local_cert'] = $sslConfig->clientCert;
            }

            if ($sslConfig->clientKey !== null) {
                $sslOptions['local_pk'] = $sslConfig->clientKey;
            }
        } else {
            // Default: always verify SSL (secure by default)
            $sslOptions = [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ];
        }

        // Proxy
        $proxyConfig = $options['proxy'] ?? null;

        if ($proxyConfig !== null) {
            $httpOptions['proxy'] = $proxyConfig->uri;
            $httpOptions['request_fulluri'] = true;
        }

        return stream_context_create([
            'http' => $httpOptions,
            'https' => $httpOptions,
            'ssl'  => $sslOptions,
        ]);
    }

    // ─── Private: Response Building ───────────────────────────────────────────

    /**
     * Reads the response from the open stream resource and constructs a Response object.
     *
     * PHP's stream wrapper exposes response headers via `stream_get_meta_data()['wrapper_data']`.
     * These are raw header strings like "HTTP/1.1 200 OK" and "Content-Type: application/json".
     *
     * @param resource $resource The open stream resource returned by fopen().
     * @param Request  $request  The originating request (for error context).
     */
    private function buildResponse(mixed $resource, Request $request): Response
    {
        $meta    = stream_get_meta_data($resource);
        $wrapper = $meta['wrapper_data'] ?? [];

        if (empty($wrapper)) {
            throw new NetworkException(
                message: 'StreamTransport received an empty response (no headers).',
                request: $request,
            );
        }

        // The first line is the status line: "HTTP/1.1 200 OK"
        $statusLine = (string) array_shift($wrapper);

        if (!preg_match('/HTTP\/(\S+)\s+(\d{3})(?:\s+(.+))?/', $statusLine, $matches)) {
            throw new NetworkException(
                message: sprintf('StreamTransport cannot parse status line: "%s"', $statusLine),
                request: $request,
            );
        }

        $version      = $matches[1];
        $statusCode   = (int) $matches[2];
        $reasonPhrase = trim($matches[3] ?? '');

        // Parse remaining header lines
        $headers = [];

        foreach ($wrapper as $line) {
            $line = trim((string) $line);

            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$name, $value]    = explode(':', $line, 2);
            $headers[trim($name)][] = trim($value);
        }

        // Read body in chunks into a Stream
        $bodyStream = Stream::empty();

        while (!feof($resource)) {
            $chunk = fread($resource, self::READ_CHUNK_SIZE);

            if ($chunk !== false && $chunk !== '') {
                $bodyStream->write($chunk);
            }
        }

        fclose($resource);

        if ($bodyStream->isSeekable()) {
            $bodyStream->rewind();
        }

        return new Response($statusCode, $headers, $bodyStream, $version, $reasonPhrase);
    }
}
