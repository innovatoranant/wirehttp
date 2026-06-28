<?php

declare(strict_types=1);

namespace WireHttp\Http\Factory;

use WireHttp\Http\Uri;

/**
 * UriFactory — Creates Uri instances from strings and components.
 *
 * WireHTTP's implementation of PSR-17's UriFactoryInterface.
 * Provides named constructors for building URIs from raw strings,
 * individual components, or base + path combinations.
 */
final class UriFactory
{
    /**
     * Creates a Uri from a raw URL string.
     *
     * @throws \InvalidArgumentException if the URI string is malformed.
     */
    public function createUri(string $uri = ''): Uri
    {
        return new Uri($uri);
    }

    /**
     * Builds a Uri from individual components.
     * Any component that is null or empty is omitted from the assembled URI.
     *
     * @param string      $scheme   e.g., "https"
     * @param string      $host     e.g., "api.example.com"
     * @param string      $path     e.g., "/v1/users"
     * @param int|null    $port     e.g., 8443 (null = use scheme default)
     * @param string      $query    e.g., "page=1&limit=25" (without "?")
     * @param string      $fragment e.g., "results" (without "#")
     * @param string|null $user     Username for userinfo
     * @param string|null $password Password for userinfo
     */
    public function createFromComponents(
        string  $scheme   = '',
        string  $host     = '',
        string  $path     = '',
        ?int    $port     = null,
        string  $query    = '',
        string  $fragment = '',
        ?string $user     = null,
        ?string $password = null,
    ): Uri {
        $uri = new Uri();
        $uri = $scheme !== ''   ? $uri->withScheme($scheme)     : $uri;
        $uri = $host   !== ''   ? $uri->withHost($host)         : $uri;
        $uri = $path   !== ''   ? $uri->withPath($path)         : $uri;
        $uri = $port   !== null ? $uri->withPort($port)         : $uri;
        $uri = $query  !== ''   ? $uri->withQuery($query)       : $uri;
        $uri = $fragment !== '' ? $uri->withFragment($fragment) : $uri;

        if ($user !== null) {
            $uri = $uri->withUserInfo($user, $password);
        }

        return $uri;
    }

    /**
     * Creates a Uri by combining a base URL with a relative path.
     * Handles path joining intelligently — removes duplicate slashes.
     *
     * @param string $base The base URL (e.g., "https://api.example.com/v1")
     * @param string $path The path to append (e.g., "/users" or "users")
     */
    public function createFromBaseAndPath(string $base, string $path): Uri
    {
        $baseUri = new Uri(rtrim($base, '/'));

        if ($path === '') {
            return $baseUri;
        }

        return $baseUri->withPath(
            rtrim($baseUri->getPath(), '/') . '/' . ltrim($path, '/')
        );
    }

    /**
     * Creates a Uri from a base URL with query parameters merged in.
     *
     * @param string               $base   The base URL
     * @param array<string, mixed> $params Query parameters to append
     */
    public function createWithQueryParams(string $base, array $params): Uri
    {
        return (new Uri($base))->withMergedQueryParams($params);
    }
}
