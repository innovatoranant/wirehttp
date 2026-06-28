<?php

declare(strict_types=1);

namespace WireHttp\Transport\Curl;

use WireHttp\Async\Deferred;
use WireHttp\Async\Future;
use WireHttp\Exceptions\NetworkException;
use WireHttp\Exceptions\TimeoutException;
use WireHttp\Http\Factory\ResponseFactory;
use WireHttp\Http\Request;
use WireHttp\Http\Response;
use WireHttp\Http\Stream;
use WireHttp\Transport\TransportInterface;

/**
 * CurlTransport — Synchronous HTTP/1.1 & HTTP/2 Transport
 *
 * The default WireHTTP transport for synchronous (blocking) requests.
 * Uses PHP's cURL extension directly for maximum performance.
 *
 * Architecture:
 * -------------
 * For every `send()` call:
 *   1. `CurlFactory::create()` builds a fully-configured `\CurlHandle`.
 *   2. `curl_exec()` is called — this blocks until the full response is received.
 *   3. We parse the raw response headers from our header buffer.
 *   4. We construct a `Response` object with the headers and body stream.
 *   5. The cURL handle is returned to the `CurlFactory` pool for reuse.
 *   6. The `Response` is returned to the caller.
 *
 * Response Streaming:
 * -------------------
 * cURL writes the response body directly into a `php://temp` stream via our
 * CURLOPT_WRITEFUNCTION callback. This means:
 *  - Responses are NEVER fully buffered in PHP memory by the transport.
 *  - `$response->text()` reads from the stream into memory (your choice).
 *  - `$response->stream()` gives you the raw stream for chunk-by-chunk reading.
 *
 * Async Support:
 * --------------
 * `sendAsync()` wraps `send()` in a resolved Future. For true concurrency,
 * use `CurlMultiHandler` instead which integrates with the Fiber event loop.
 */
final class CurlTransport implements TransportInterface
{
    private readonly CurlFactory $factory;
    private readonly ResponseFactory $responseFactory;

    public function __construct(
        ?CurlFactory $factory = null,
        ?ResponseFactory $responseFactory = null,
    ) {
        $this->factory         = $factory ?? new CurlFactory();
        $this->responseFactory = $responseFactory ?? new ResponseFactory();
    }

    // ─── TransportInterface ───────────────────────────────────────────────────

    /**
     * Sends an HTTP request synchronously and returns the Response.
     *
     * @throws NetworkException  On cURL transport failure.
     * @throws TimeoutException  On connect/request timeout.
     */
    public function send(Request $request): Response
    {
        $responseHeaders = '';
        $bodyStream      = Stream::empty();

        $curlHandle = $this->factory->create($request, $responseHeaders, $bodyStream);

        $poolKey = $this->factory->getPoolKey($request);

        try {
            $execResult = curl_exec($curlHandle);

            if ($execResult === false) {
                $errno = curl_errno($curlHandle);

                // Distinguish timeout from generic network errors
                if ($errno === CURLE_OPERATION_TIMEDOUT) {
                    $info = curl_getinfo($curlHandle);

                    $connectTime  = (float) ($info['connect_time'] ?? 0);
                    $totalTime    = (float) ($info['total_time'] ?? 0);
                    $configuredTimeout = $request->getOption('timeout')?->requestSeconds ?? 30.0;

                    // If connect_time is nearly zero, it was a connect timeout
                    $isConnect = $connectTime < 0.001;

                    throw new TimeoutException(
                        connectTimeout: $isConnect,
                        configuredTimeoutSeconds: $configuredTimeout,
                        elapsedSeconds: round($totalTime, 4),
                        curlErrorNo: $errno,
                        curlErrorString: curl_error($curlHandle),
                        request: $request,
                    );
                }

                throw NetworkException::fromCurlHandle($curlHandle, $request);
            }

            $response = $this->buildResponse($responseHeaders, $bodyStream, $curlHandle);

        } finally {
            // Always recycle the handle back to the pool (even on exception)
            $this->factory->recycle($curlHandle, $poolKey);
        }

        return $response;
    }

    /**
     * Wraps `send()` in a pre-resolved Future.
     * For true concurrent async requests, use CurlMultiHandler.
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
     * Returns true if the cURL extension is loaded and functional.
     */
    public function isAvailable(): bool
    {
        return extension_loaded('curl') && function_exists('curl_init');
    }

    /**
     * Returns a human-readable transport identifier including the cURL version.
     */
    public function name(): string
    {
        if (!$this->isAvailable()) {
            return 'curl/unavailable';
        }

        $info = curl_version();

        return sprintf('curl/%s', $info['version'] ?? 'unknown');
    }

    // ─── Private: Response Building ───────────────────────────────────────────

    /**
     * Constructs a Response from the raw cURL output.
     *
     * @param string       $rawHeaders  The accumulated raw header bytes.
     * @param Stream       $bodyStream  The stream containing the response body.
     * @param \CurlHandle  $curlHandle  The cURL handle (for curl_getinfo metadata).
     */
    private function buildResponse(
        string      $rawHeaders,
        Stream      $bodyStream,
        \CurlHandle $curlHandle,
    ): Response {
        // Rewind the body stream to position 0 so callers can read from the start
        if ($bodyStream->isSeekable()) {
            $bodyStream->rewind();
        }

        // curl_getinfo gives us the effective URL, HTTP version, status code etc.
        $info       = curl_getinfo($curlHandle);
        $statusCode = (int) ($info['http_code'] ?? 200);

        // Parse raw headers string into a structured headers array.
        // cURL provides ALL headers including those from intermediate redirects
        // (e.g., 301 redirect headers + final 200 headers). We take the LAST set.
        [$version, $reasonPhrase, $headers] = $this->parseRawHeaders($rawHeaders);

        return new Response(
            statusCode: $statusCode,
            headers: $headers,
            body: $bodyStream,
            version: $version,
            reasonPhrase: $reasonPhrase,
        );
    }

    /**
     * Parses raw cURL header bytes into [version, reason, headers array].
     *
     * Handles multiple header blocks (from redirects) by taking the LAST block.
     * Each block is separated by "\r\n\r\n".
     *
     * @return array{0: string, 1: string, 2: array<string, list<string>>}
     */
    private function parseRawHeaders(string $rawHeaders): array
    {
        // Split into header blocks. The last block is the final response.
        $blocks = preg_split('/\r\n\r\n|\n\n/', trim($rawHeaders));
        $block  = end($blocks);

        if ($block === false || $block === '') {
            return ['1.1', 'OK', []];
        }

        $lines  = preg_split('/\r\n|\n/', trim($block));
        $status = array_shift($lines);

        // Parse "HTTP/1.1 200 OK" or "HTTP/2 200"
        preg_match('/HTTP\/(\S+)\s+\d+\s*(.*)?/', (string) $status, $matches);

        $version      = $matches[1] ?? '1.1';
        $reasonPhrase = trim($matches[2] ?? '');

        $headers = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name           = trim($name);
            $value          = trim($value);

            $lowerName = strtolower($name);
            $headers[$lowerName][] = $value;
        }

        return [$version, $reasonPhrase, $headers];
    }
}
