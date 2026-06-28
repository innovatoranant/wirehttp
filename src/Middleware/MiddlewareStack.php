<?php

declare(strict_types=1);

namespace WireHttp\Middleware;

use WireHttp\Http\Request;
use WireHttp\Http\Response;
use WireHttp\Transport\TransportInterface;

/**
 * MiddlewareStack — Ultra-Fast Array-Based Middleware Pipeline
 *
 * The MiddlewareStack is responsible for assembling all registered middleware
 * into a single callable pipeline and executing it for each HTTP request.
 *
 * Architecture:
 * -------------
 * Internally we maintain an ordered array of MiddlewareInterface instances.
 * When `process()` is called, we build a "composed" callable by folding the
 * middleware array from right to left — wrapping each middleware around the next.
 * The innermost callable is the transport layer's `send()` method.
 *
 * This approach avoids recursive function calls and is extremely cache-friendly
 * because the composed callable is built once and cached per-stack-instance.
 * Subsequent calls reuse the cached composed handler.
 *
 * Execution Order:
 * ----------------
 * Middleware is executed in the ORDER it was added (FIFO for request processing,
 * LIFO for response processing — like an onion):
 *
 *   addMiddleware(A) → addMiddleware(B) → addMiddleware(C)
 *
 *   Request:   A → B → C → Transport
 *   Response:  A ← B ← C ← Transport
 *
 * This means:
 *   - Authentication middleware should be added LAST (runs closest to the wire).
 *   - Logging middleware should be added FIRST (wraps everything).
 *   - Retry middleware should be added BEFORE authentication (so retries re-sign).
 *
 * Immutability:
 * -------------
 * `withMiddleware()` returns a NEW MiddlewareStack instance with the middleware
 * prepended. This makes stacks safe to share across Client instances without
 * mutation side effects.
 */
final class MiddlewareStack
{
    /**
     * Ordered list of middleware.
     * Index 0 is the outermost (first to process the request, last to process the response).
     *
     * @var list<MiddlewareInterface>
     */
    private array $middleware = [];

    /**
     * Cached composed handler callable.
     * Invalidated whenever middleware is added or removed.
     */
    private ?\Closure $composedHandler = null;

    /**
     * The transport layer at the bottom of the stack.
     */
    private readonly TransportInterface $transport;

    public function __construct(TransportInterface $transport)
    {
        $this->transport = $transport;
    }

    // ─── Middleware Registration ───────────────────────────────────────────────

    /**
     * Appends middleware to the END of the stack (runs closest to the transport).
     *
     * @return static Returns $this for fluent chaining.
     */
    public function push(MiddlewareInterface ...$middleware): static
    {
        foreach ($middleware as $m) {
            $this->middleware[] = $m;
        }

        $this->composedHandler = null; // Invalidate cache

        return $this;
    }

    /**
     * Prepends middleware to the START of the stack (runs outermost / first).
     *
     * @return static Returns $this for fluent chaining.
     */
    public function prepend(MiddlewareInterface ...$middleware): static
    {
        foreach (array_reverse($middleware) as $m) {
            array_unshift($this->middleware, $m);
        }

        $this->composedHandler = null;

        return $this;
    }

    /**
     * Returns a NEW MiddlewareStack with the given middleware prepended.
     * The original stack is not modified (immutable operation).
     */
    public function withMiddleware(MiddlewareInterface ...$middleware): static
    {
        $clone = clone $this;

        foreach (array_reverse($middleware) as $m) {
            array_unshift($clone->middleware, $m);
        }

        $clone->composedHandler = null;

        return $clone;
    }

    /**
     * Removes all middleware from this stack.
     * Returns $this for fluent chaining.
     */
    public function flush(): static
    {
        $this->middleware      = [];
        $this->composedHandler = null;

        return $this;
    }

    /**
     * Returns a snapshot of all registered middleware, in execution order.
     *
     * @return list<MiddlewareInterface>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Returns the number of middleware registered.
     */
    public function count(): int
    {
        return count($this->middleware);
    }

    // ─── Pipeline Execution ───────────────────────────────────────────────────

    /**
     * Runs the request through the full middleware pipeline and returns the Response.
     *
     * The pipeline is built lazily on first call and then cached.
     * Subsequent calls reuse the same composed handler for maximum performance.
     *
     * @param Request $request The HTTP request to process.
     * @return Response The final HTTP response.
     */
    public function process(Request $request): Response
    {
        return ($this->getComposedHandler())($request);
    }

    /**
     * Builds and caches the composed middleware handler.
     *
     * We fold the middleware array from right to left, wrapping each middleware
     * around the handler to its right. The rightmost handler is the transport.
     *
     * Example with [A, B, C] and Transport T:
     *   Step 1: handler = fn($req) => T->send($req)
     *   Step 2: handler = fn($req) => C->process($req, prev_handler)
     *   Step 3: handler = fn($req) => B->process($req, prev_handler)
     *   Step 4: handler = fn($req) => A->process($req, prev_handler)
     *
     * The final handler executes A, which calls B, which calls C, which calls T.
     */
    private function getComposedHandler(): \Closure
    {
        if ($this->composedHandler !== null) {
            return $this->composedHandler;
        }

        $transport = $this->transport;

        // The innermost handler calls the transport directly
        $handler = static function (Request $request) use ($transport): Response {
            return $transport->send($request);
        };

        // Fold from right to left so execution order matches the array order
        foreach (array_reverse($this->middleware) as $middleware) {
            $currentHandler = $handler;

            $handler = static function (Request $request) use ($middleware, $currentHandler): Response {
                return $middleware->process($request, $currentHandler);
            };
        }

        return $this->composedHandler = $handler;
    }
}
