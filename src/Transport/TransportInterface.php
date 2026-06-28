<?php

declare(strict_types=1);

namespace WireHttp\Transport;

use WireHttp\Async\Future;
use WireHttp\Http\Request;
use WireHttp\Http\Response;

/**
 * TransportInterface — The Contract for All Network Backends
 *
 * This interface decouples WireHTTP's high-level Client from the low-level
 * network I/O mechanism. It allows different transport implementations to be
 * swapped in transparently:
 *
 *   - CurlTransport:       Default. Uses PHP's cURL extension for HTTP/1.1 & HTTP/2.
 *   - CurlMultiHandler:    Async extension. Adds curl_multi + Fiber concurrency.
 *   - StreamTransport:     Fallback. Uses PHP's native stream wrappers (no cURL).
 *   - MockTransport:       For testing. Returns pre-queued fake responses.
 *
 * Every method on this interface is typed. There are no untyped arrays.
 * There is no "options" bag — all configuration is done through the Request
 * object's `getOptions()` and dedicated Configuration DTOs.
 *
 * Synchronous vs Asynchronous:
 * ----------------------------
 * Every transport implements BOTH sync and async methods.
 * - `send()`:      Blocking — waits for the response before returning.
 * - `sendAsync()`: Non-blocking — returns a Future immediately.
 *
 * For transports that do not natively support async (e.g., StreamTransport),
 * `sendAsync()` wraps `send()` in a resolved Future — making the API uniform.
 *
 * @see CurlTransport
 * @see CurlMultiHandler
 * @see StreamTransport
 * @see MockTransport
 */
interface TransportInterface
{
    /**
     * Sends an HTTP request synchronously and returns the response.
     *
     * This is a blocking call. The PHP process waits until the complete
     * HTTP response has been received from the server before returning.
     *
     * @param Request $request The fully-constructed, immutable HTTP request to send.
     * @return Response        The HTTP response received from the server.
     *
     * @throws \WireHttp\Exceptions\NetworkException   On DNS/connection/TLS failure.
     * @throws \WireHttp\Exceptions\TimeoutException   On connect or request timeout.
     * @throws \WireHttp\Exceptions\ClientException    On 4xx responses (if error throwing is enabled).
     * @throws \WireHttp\Exceptions\ServerException    On 5xx responses (if error throwing is enabled).
     */
    public function send(Request $request): Response;

    /**
     * Sends an HTTP request asynchronously and returns a Future immediately.
     *
     * This is a non-blocking call. The Future will resolve with the Response
     * when the server replies, or reject with an exception on failure.
     *
     * Calling `$future->get()` from within a PHP Fiber will suspend the Fiber
     * (not the entire process) until the response arrives.
     *
     * @param Request $request The fully-constructed, immutable HTTP request to send.
     * @return Future<Response> A Future that will resolve with the Response.
     */
    public function sendAsync(Request $request): Future;

    /**
     * Returns true if this transport is available and operational in the current
     * PHP environment.
     *
     * For example, CurlTransport returns false if ext-curl is not loaded.
     * StreamTransport returns false if allow_url_fopen is disabled.
     */
    public function isAvailable(): bool;

    /**
     * Returns a human-readable name for this transport implementation.
     * Used in diagnostic messages and error reporting.
     *
     * Examples: "curl/7.88.1", "stream/php8.2", "mock"
     */
    public function name(): string;
}
