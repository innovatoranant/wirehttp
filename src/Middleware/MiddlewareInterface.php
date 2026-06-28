<?php

declare(strict_types=1);

namespace WireHttp\Middleware;

use WireHttp\Http\Request;
use WireHttp\Http\Response;

/**
 * MiddlewareInterface — The Contract for All WireHTTP Interceptors
 *
 * Every piece of middleware in WireHTTP — whether built-in (redirect handling,
 * retry logic, cookie management) or user-defined — must implement this interface.
 *
 * The Interceptor Pattern:
 * ------------------------
 * Middleware forms a "chain of responsibility". Each middleware:
 *   1. Receives the current Request.
 *   2. Optionally modifies the Request (e.g., adds headers, injects auth tokens).
 *   3. Passes the (possibly modified) Request to the NEXT handler in the chain.
 *   4. Receives the Response from the next handler.
 *   5. Optionally modifies or replaces the Response (e.g., decodes body, follows redirect).
 *   6. Returns the (possibly modified) Response to the previous handler.
 *
 * The "next" handler is encapsulated in the `$next` callable parameter.
 * Calling `$next($request)` executes the rest of the pipeline (all subsequent
 * middleware + the transport layer at the very end).
 *
 * Visual representation:
 *
 *   Request →  [Middleware A]  →  [Middleware B]  →  [Middleware C]  →  Transport
 *   Response ← [Middleware A]  ←  [Middleware B]  ←  [Middleware C]  ←  Transport
 *
 * Short-Circuiting:
 * -----------------
 * A middleware can choose NOT to call `$next()`. Instead, it can return a Response
 * directly. This is used by:
 *   - CircuitBreakerInterceptor: Returns a fake 503 without touching the network.
 *   - MockTransport: Returns queued responses without making real requests.
 *
 * The `$next` callable signature:
 *   function(Request $request): Response
 *
 * Usage:
 *   class AddAuthHeaderMiddleware implements MiddlewareInterface {
 *       public function process(Request $request, callable $next): Response {
 *           $request = $request->withHeader('Authorization', 'Bearer ' . $this->token);
 *           return $next($request);
 *       }
 *   }
 */
interface MiddlewareInterface
{
    /**
     * Processes the request through this middleware and returns the response.
     *
     * @param Request  $request The incoming HTTP request.
     * @param callable $next    The next handler in the pipeline.
     *                          Signature: callable(Request): Response
     *
     * @return Response The final HTTP response (possibly modified by this middleware).
     *
     * @throws \WireHttp\Exceptions\WireHttpException or any other exception on failure.
     */
    public function process(Request $request, callable $next): Response;
}
