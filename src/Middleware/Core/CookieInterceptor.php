<?php

declare(strict_types=1);

namespace WireHttp\Middleware\Core;

use WireHttp\Http\Request;
use WireHttp\Http\Response;
use WireHttp\Middleware\MiddlewareInterface;

/**
 * CookieInterceptor — Transparent Cookie Management Middleware
 *
 * Works in partnership with a CookieJar to:
 *   1. Inject matching cookies into every outgoing request.
 *   2. Parse and store cookies received in every incoming response.
 *
 * This enables stateful "session-like" behavior across multiple requests
 * without the developer having to manually track cookie headers.
 *
 * Usage:
 *   $jar       = new CookieJar();
 *   $intercept = new CookieInterceptor($jar);
 *   $client    = new Client(middleware: [$intercept]);
 *
 *   // First request: server sets a session cookie
 *   $client->post('/login', ['username' => 'alice', 'password' => 'secret']);
 *
 *   // Second request: cookie is automatically included
 *   $client->get('/dashboard'); // Cookie header injected by CookieInterceptor
 *
 * The interceptor uses the CookieJar's RFC 6265 matching algorithm to ensure
 * only cookies appropriate for the request's domain/path/scheme are sent.
 */
final class CookieInterceptor implements MiddlewareInterface
{
    private readonly CookieJar $jar;

    /**
     * If true, existing Cookie headers on the Request are MERGED with the jar's cookies.
     * If false, the jar completely replaces any existing Cookie header.
     */
    private readonly bool $mergeExistingHeaders;

    public function __construct(
        ?CookieJar $jar = null,
        bool $mergeExistingHeaders = true,
    ) {
        $this->jar                  = $jar ?? new CookieJar();
        $this->mergeExistingHeaders = $mergeExistingHeaders;
    }

    public function process(Request $request, callable $next): Response
    {
        // ── Step 1: Inject matching cookies into the outgoing request ──────────
        $request = $this->addCookiesToRequest($request);

        // ── Step 2: Pass through to the next middleware / transport ───────────
        $response = $next($request);

        // ── Step 3: Store cookies from the response ───────────────────────────
        $this->storeCookiesFromResponse($response, $request);

        return $response;
    }

    // ─── Cookie Injection ─────────────────────────────────────────────────────

    private function addCookiesToRequest(Request $request): Request
    {
        $jarCookies = $this->jar->getCookieHeaderForRequest($request->getUri());

        if ($jarCookies === '') {
            return $request;
        }

        if ($this->mergeExistingHeaders) {
            $existingCookies = $request->getHeaderLine('Cookie');

            $merged = $existingCookies !== ''
                ? $existingCookies . '; ' . $jarCookies
                : $jarCookies;

            return $request->withHeader('Cookie', $merged);
        }

        return $request->withHeader('Cookie', $jarCookies);
    }

    // ─── Cookie Storage ───────────────────────────────────────────────────────

    private function storeCookiesFromResponse(Response $response, Request $request): void
    {
        $setCookieHeaders = $response->getCookieHeaders();

        if (empty($setCookieHeaders)) {
            return;
        }

        $this->jar->storeCookiesFromResponse($setCookieHeaders, $request->getUri());
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Returns the CookieJar backing this interceptor.
     * Useful for inspecting stored cookies or manually adding/removing them.
     */
    public function getJar(): CookieJar
    {
        return $this->jar;
    }

    /**
     * Clears all session cookies from the jar (simulates browser session end).
     */
    public function clearSession(): void
    {
        $this->jar->clearSession();
    }

    /**
     * Clears ALL cookies from the jar.
     */
    public function clearAll(): void
    {
        $this->jar->clear();
    }
}
