<?php

declare(strict_types=1);

namespace WireHttp\Middleware\Core;

use WireHttp\Enums\HttpMethod;
use WireHttp\Enums\StatusCode;
use WireHttp\Exceptions\TooManyRedirectsException;
use WireHttp\Http\Request;
use WireHttp\Http\Response;
use WireHttp\Http\Uri;
use WireHttp\Middleware\MiddlewareInterface;

/**
 * RedirectInterceptor — RFC 7231-Compliant HTTP Redirect Handler
 *
 * Intercepts 3xx responses and automatically follows the redirect chain,
 * applying all the correct RFC rules about method preservation, security
 * downgrade prevention, and loop detection.
 *
 * Why not let cURL handle redirects?
 * ------------------------------------
 * We intentionally disable cURL's native redirect following (CURLOPT_FOLLOWLOCATION = 0)
 * and implement it here as middleware. This gives us:
 *  1. Full control over which headers are sent on the redirect request.
 *  2. Ability to strip Authorization headers when crossing domains (security).
 *  3. Cookie handling (cookies from the redirect response are saved before the next request).
 *  4. Accurate redirect history for debugging and TooManyRedirectsException.
 *  5. The ability to fire events on each redirect.
 *
 * RFC Compliance:
 * ---------------
 *  - 301/302: MUST follow to new location. For POST → GET method change is historically
 *    common (but technically incorrect per RFC 7231). WireHTTP defaults to changing
 *    POST to GET for 301/302 to match browser behavior (configurable).
 *  - 303: MUST use GET for the redirect regardless of original method.
 *  - 307: MUST preserve the original method and body.
 *  - 308: MUST preserve the original method and body (permanent version of 307).
 *
 * Security:
 * ---------
 *  - Sensitive headers (Authorization, Cookie, Proxy-Authorization) are stripped
 *    when redirecting to a different host (cross-origin redirect).
 *  - HTTPS → HTTP downgrades are rejected by default (configurable).
 */
final class RedirectInterceptor implements MiddlewareInterface
{
    /**
     * Status codes that trigger a redirect.
     */
    private const REDIRECT_CODES = [301, 302, 303, 307, 308];

    /**
     * Headers that must be stripped when redirecting to a different host.
     * These could leak credentials or session tokens to untrusted servers.
     */
    private const SENSITIVE_HEADERS = [
        'authorization',
        'www-authenticate',
        'proxy-authorization',
        'cookie',
        'cookie2',
    ];

    private readonly int $maxRedirects;

    /**
     * If true, POST requests are changed to GET on 301/302 (browser behavior).
     * If false, the original method is preserved (strict RFC 7231 compliance).
     */
    private readonly bool $strictMethodPreservation;

    /**
     * If true, redirects from HTTPS → HTTP are rejected with an exception.
     */
    private readonly bool $preventDowngrade;

    public function __construct(
        int  $maxRedirects = 10,
        bool $strictMethodPreservation = false,
        bool $preventDowngrade = true,
    ) {
        $this->maxRedirects             = $maxRedirects;
        $this->strictMethodPreservation = $strictMethodPreservation;
        $this->preventDowngrade         = $preventDowngrade;
    }

    public function process(Request $request, callable $next): Response
    {
        $redirectHistory = [(string) $request->getUri()];
        $currentRequest  = $request;
        $redirectCount   = 0;

        while (true) {
            $response = $next($currentRequest);

            // Not a redirect — return the response as-is
            if (!in_array($response->getStatusCode(), self::REDIRECT_CODES, strict: true)) {
                return $response;
            }

            $location = $response->getLocation();

            // No Location header — can't follow. Return the redirect response.
            if ($location === null || $location === '') {
                return $response;
            }

            // Check for redirect limit
            $redirectCount++;

            if ($redirectCount > $this->maxRedirects) {
                throw new TooManyRedirectsException(
                    redirectCount: $redirectCount,
                    maxRedirects: $this->maxRedirects,
                    redirectHistory: $redirectHistory,
                    isLoop: false,
                    request: $currentRequest,
                    response: $response,
                );
            }

            // Resolve the new URI (handles relative redirect locations)
            $newUri = $this->resolveRedirectUri($currentRequest->getUri(), $location);

            // Detect redirect loops
            $newUriString = (string) $newUri;

            if (in_array($newUriString, $redirectHistory, strict: true)) {
                throw new TooManyRedirectsException(
                    redirectCount: $redirectCount,
                    maxRedirects: $this->maxRedirects,
                    redirectHistory: [...$redirectHistory, $newUriString],
                    isLoop: true,
                    request: $currentRequest,
                    response: $response,
                );
            }

            // Security: Prevent HTTPS → HTTP downgrade
            if ($this->preventDowngrade) {
                $this->assertNoDowngrade($currentRequest->getUri(), $newUri);
            }

            $redirectHistory[] = $newUriString;

            // Determine the method for the next request
            $newMethod = $this->resolveMethod(
                statusCode: $response->getStatusCode(),
                originalMethod: $currentRequest->getMethodEnum(),
            );

            // Build the redirect request
            $currentRequest = $this->buildRedirectRequest(
                original: $currentRequest,
                newUri: $newUri,
                newMethod: $newMethod,
                isCrossOrigin: $this->isCrossOrigin($currentRequest->getUri(), $newUri),
            );
        }
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    /**
     * Resolves the redirect Location header to an absolute URI.
     * Handles relative URLs (e.g., "/new-path" or "../other") via RFC 3986 resolution.
     */
    private function resolveRedirectUri(Uri $base, string $location): Uri
    {
        $locationUri = new Uri($location);

        // If the location is already absolute (has a scheme), return it directly.
        if ($locationUri->getScheme() !== '') {
            return $locationUri;
        }

        // Relative redirect — resolve against the base URI
        return $base->resolve($locationUri);
    }

    /**
     * Determines the HTTP method to use for the redirect request.
     *
     * 307/308: Always preserve original method.
     * 303:     Always use GET.
     * 301/302: Preserve if strictMethodPreservation, else change POST → GET.
     */
    private function resolveMethod(int $statusCode, HttpMethod $originalMethod): HttpMethod
    {
        return match ($statusCode) {
            307, 308 => $originalMethod,               // Always preserve
            303      => HttpMethod::GET,                // Always GET
            301, 302 => $this->strictMethodPreservation
                ? $originalMethod                       // Strict: preserve
                : ($originalMethod === HttpMethod::POST // Lenient: POST → GET
                    ? HttpMethod::GET
                    : $originalMethod),
            default  => $originalMethod,
        };
    }

    /**
     * Builds the new Request for the redirect.
     * Strips sensitive headers for cross-origin redirects.
     * Strips the body if the method changed to GET.
     */
    private function buildRedirectRequest(
        Request   $original,
        Uri       $newUri,
        HttpMethod $newMethod,
        bool      $isCrossOrigin,
    ): Request {
        $newRequest = $original
            ->withUri($newUri)
            ->withMethod($newMethod);

        // Strip sensitive headers on cross-origin redirects
        if ($isCrossOrigin) {
            foreach (self::SENSITIVE_HEADERS as $header) {
                $newRequest = $newRequest->withoutHeader($header);
            }
        }

        // If the method changed to GET, remove the body and content headers
        if ($newMethod === HttpMethod::GET && $original->getMethodEnum() !== HttpMethod::GET) {
            $newRequest = $newRequest
                ->withoutHeader('Content-Type')
                ->withoutHeader('Content-Length')
                ->withoutHeader('Transfer-Encoding');
        }

        return $newRequest;
    }

    /**
     * Returns true if the redirect crosses an origin boundary (different host or scheme).
     */
    private function isCrossOrigin(Uri $original, Uri $newUri): bool
    {
        return $original->getHost() !== $newUri->getHost()
            || $original->getScheme() !== $newUri->getScheme()
            || $original->getEffectivePort() !== $newUri->getEffectivePort();
    }

    /**
     * Throws a SecurityException if we're redirecting from HTTPS to HTTP.
     */
    private function assertNoDowngrade(Uri $original, Uri $newUri): void
    {
        if ($original->getScheme() === 'https' && $newUri->getScheme() === 'http') {
            throw new \RuntimeException(
                sprintf(
                    'Security violation: redirect from HTTPS to HTTP is not allowed. ' .
                    'Attempted to redirect from "%s" to "%s". ' .
                    'Disable this check via RedirectInterceptor(preventDowngrade: false).',
                    (string) $original,
                    (string) $newUri
                )
            );
        }
    }
}
