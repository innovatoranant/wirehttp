<?php

declare(strict_types=1);

namespace WireHttp\Http\Factory;

use WireHttp\Http\Request;
use WireHttp\Http\Stream;
use WireHttp\Http\Uri;
use WireHttp\Enums\HttpMethod;

/**
 * RequestFactory — Creates Request instances from primitive values.
 *
 * This is WireHTTP's implementation of PSR-17's RequestFactoryInterface.
 * It provides convenient named constructors for creating Request objects
 * without having to manually instantiate all dependencies.
 *
 * The transport layer uses this factory internally. Developers should
 * use the fluent RequestBuilder (via Wire::get(), Wire::post(), etc.) instead.
 */
final class RequestFactory
{
    /**
     * Creates a new Request with the given method and URI.
     */
    public function createRequest(string $method, string|Uri $uri): Request
    {
        return new Request(
            method: HttpMethod::fromString($method),
            uri: $uri instanceof Uri ? $uri : new Uri((string) $uri),
        );
    }

    /**
     * Creates a GET request.
     */
    public function get(string|Uri $uri, array $headers = []): Request
    {
        return new Request(HttpMethod::GET, $uri, $headers);
    }

    /**
     * Creates a POST request with an optional body.
     */
    public function post(string|Uri $uri, array $headers = [], ?Stream $body = null): Request
    {
        return new Request(HttpMethod::POST, $uri, $headers, $body);
    }

    /**
     * Creates a PUT request with an optional body.
     */
    public function put(string|Uri $uri, array $headers = [], ?Stream $body = null): Request
    {
        return new Request(HttpMethod::PUT, $uri, $headers, $body);
    }

    /**
     * Creates a PATCH request with an optional body.
     */
    public function patch(string|Uri $uri, array $headers = [], ?Stream $body = null): Request
    {
        return new Request(HttpMethod::PATCH, $uri, $headers, $body);
    }

    /**
     * Creates a DELETE request.
     */
    public function delete(string|Uri $uri, array $headers = []): Request
    {
        return new Request(HttpMethod::DELETE, $uri, $headers);
    }

    /**
     * Creates a HEAD request.
     */
    public function head(string|Uri $uri, array $headers = []): Request
    {
        return new Request(HttpMethod::HEAD, $uri, $headers);
    }

    /**
     * Creates an OPTIONS request.
     */
    public function options(string|Uri $uri, array $headers = []): Request
    {
        return new Request(HttpMethod::OPTIONS, $uri, $headers);
    }
}
