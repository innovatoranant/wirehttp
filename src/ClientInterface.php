<?php

declare(strict_types=1);

namespace WireHttp;

use WireHttp\Async\Future;
use WireHttp\Http\Request;
use WireHttp\Http\Response;
use WireHttp\Request\Builder\RequestBuilder;
use WireHttp\Response\ResponseDecorator;

/**
 * ClientInterface — PSR-18-Compatible HTTP Client Contract
 *
 * Defines the surface area of WireHTTP's Client class. Implementing this
 * interface instead of depending on the concrete `Client` class enables:
 *   - Easy mocking in tests via mock/fake clients.
 *   - Dependency inversion (depend on abstraction, not implementation).
 *   - Framework integration (swap WireHTTP for another PSR-18 client).
 *
 * PSR-18 compatibility:
 * ---------------------
 * The `sendRequest()` method matches PSR-18's exact signature:
 *   sendRequest(RequestInterface $request): ResponseInterface
 *
 * While WireHTTP uses its own `Request`/`Response` types (which are PSR-7
 * compatible), the `sendRequest()` method accepts WireHTTP's `Request` type.
 * For true PSR-18 interop, pass through a PSR-7 factory adapter.
 */
interface ClientInterface
{
    /**
     * PSR-18 compatible: sends a raw Request and returns the Response.
     * This is the low-level entry point — bypasses the fluent builder API.
     */
    public function sendRequest(Request $request): Response;

    /**
     * Sends a raw Request asynchronously. Returns a Future<Response>.
     *
     * @return Future<Response>
     */
    public function sendRequestAsync(Request $request): Future;

    /**
     * Returns a RequestBuilder configured for a GET request to the given URI.
     */
    public function get(string $uri, array $query = []): RequestBuilder;

    /**
     * Returns a RequestBuilder configured for a POST request.
     */
    public function post(string $uri): RequestBuilder;

    /**
     * Returns a RequestBuilder configured for a PUT request.
     */
    public function put(string $uri): RequestBuilder;

    /**
     * Returns a RequestBuilder configured for a PATCH request.
     */
    public function patch(string $uri): RequestBuilder;

    /**
     * Returns a RequestBuilder configured for a DELETE request.
     */
    public function delete(string $uri): RequestBuilder;

    /**
     * Returns a RequestBuilder configured for a HEAD request.
     */
    public function head(string $uri): RequestBuilder;

    /**
     * Returns a RequestBuilder configured for a custom HTTP method.
     */
    public function request(string $method, string $uri): RequestBuilder;
}
