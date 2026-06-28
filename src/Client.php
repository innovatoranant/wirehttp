<?php

declare(strict_types=1);

namespace WireHttp;

use WireHttp\Async\Future;
use WireHttp\Configuration\ClientConfig;
use WireHttp\Event\EventDispatcher;
use WireHttp\Event\Events\RequestSendingEvent;
use WireHttp\Event\Events\ResponseReceivedEvent;
use WireHttp\Http\Request;
use WireHttp\Http\Response;
use WireHttp\Middleware\Core\CircuitBreakerInterceptor;
use WireHttp\Middleware\Core\CookieInterceptor;
use WireHttp\Middleware\Core\CookieJar;
use WireHttp\Middleware\Core\RedirectInterceptor;
use WireHttp\Middleware\Core\RetryInterceptor;
use WireHttp\Middleware\MiddlewareInterface;
use WireHttp\Middleware\MiddlewareStack;
use WireHttp\Request\Builder\RequestBuilder;
use WireHttp\Response\Hydrator\AttributeHydrator;
use WireHttp\Response\ResponseDecorator;
use WireHttp\Transport\Curl\CurlMultiHandler;
use WireHttp\Transport\Curl\CurlTransport;
use WireHttp\Transport\Mock\MockTransport;
use WireHttp\Transport\Stream\StreamTransport;
use WireHttp\Transport\TransportInterface;

/**
 * Client — The WireHTTP Core HTTP Client
 *
 * The Client is the orchestrator of WireHTTP. It:
 *   1. Accepts a `ClientConfig` for all connection, SSL, retry, and cookie settings.
 *   2. Auto-selects the best available transport (CurlMultiHandler > CurlTransport > StreamTransport).
 *   3. Assembles the `MiddlewareStack` from the config (redirects, retries, cookies, circuit breaker).
 *   4. Exposes a fluent `RequestBuilder` API via `get()`, `post()`, `put()`, etc.
 *   5. Fires events via the `EventDispatcher` for observability (logging, APM, caching).
 *
 * Lazy Transport Selection:
 * -------------------------
 * The transport is selected at construction time in this priority order:
 *   1. Explicit transport passed in the constructor.
 *   2. `CurlMultiHandler` (if `ext-curl` is loaded and PHP >= 8.1 with Fibers).
 *   3. `CurlTransport` (if `ext-curl` is loaded, PHP < 8.1).
 *   4. `StreamTransport` (if `allow_url_fopen` is on — fallback, no concurrency).
 *   5. Throws `\RuntimeException` if none are available.
 *
 * Middleware Stack Assembly:
 * --------------------------
 * Built from ClientConfig, in this execution order (outermost first):
 *   [1] EventDispatcherMiddleware (fires RequestSendingEvent / ResponseReceivedEvent)
 *   [2] RetryInterceptor          (if config->maxRetries > 0)
 *   [3] CircuitBreakerInterceptor  (if configured)
 *   [4] RedirectInterceptor        (if config->followRedirects)
 *   [5] CookieInterceptor          (if config->manageCookies)
 *   ─── Transport ────────────────────────────────────────────────────────────
 *
 * Usage:
 *   $client = new Client(ClientConfig::create()
 *       ->withBaseUri('https://api.example.com')
 *       ->withDefaultHeader('Accept', 'application/json')
 *       ->withRetry(3)
 *       ->withTimeout(TimeoutConfig::fast())
 *   );
 *
 *   $user = $client->get('/users/1')->send()->into(UserDto::class);
 *
 * Immutable Config Overrides (per-request):
 *   $response = $client->get('/heavy-endpoint')
 *       ->timeout(120.0)   // overrides client-level timeout for this request only
 *       ->withBearer($token)
 *       ->send();
 */
final class Client implements ClientInterface
{
    private readonly TransportInterface $transport;
    private readonly MiddlewareStack    $stack;
    private readonly ClientConfig       $config;
    private readonly EventDispatcher    $events;
    private readonly AttributeHydrator  $hydrator;

    public function __construct(
        ?ClientConfig        $config = null,
        ?TransportInterface  $transport = null,
        ?EventDispatcher     $events = null,
        MiddlewareInterface  ...$extraMiddleware,
    ) {
        $this->config    = $config ?? ClientConfig::create();
        $this->events    = $events ?? new EventDispatcher();
        $this->hydrator  = new AttributeHydrator();
        $this->transport = $transport ?? $this->resolveTransport();
        $this->stack     = $this->buildMiddlewareStack($extraMiddleware);
    }

    // ─── ClientInterface ─────────────────────────────────────────────────────

    /**
     * Sends a raw Request through the full middleware pipeline.
     * Returns the raw Response (not a ResponseDecorator).
     */
    public function sendRequest(Request $request): Response
    {
        $request = $this->applyDefaultHeaders($request);

        $startedAt = microtime(as_float: true);

        // Fire pre-request event
        if ($this->events->hasListeners(RequestSendingEvent::class)) {
            $event   = new RequestSendingEvent($request);
            $this->events->dispatch($event);
            $request = $event->getRequest(); // Listeners may have modified the request
        }

        $response = $this->stack->process($request);

        // Fire post-response event
        if ($this->events->hasListeners(ResponseReceivedEvent::class)) {
            $this->events->dispatch(new ResponseReceivedEvent($request, $response, $startedAt));
        }

        return $response;
    }

    /**
     * Sends a raw Request asynchronously.
     *
     * @return Future<Response>
     */
    public function sendRequestAsync(Request $request): Future
    {
        $request = $this->applyDefaultHeaders($request);

        if (!method_exists($this->transport, 'sendAsync')) {
            return Future::resolved($this->sendRequest($request));
        }

        return $this->transport->sendAsync($request)->map(function (Response $response) use ($request): Response {
            return $response;
        });
    }

    // ─── Fluent Builder API ───────────────────────────────────────────────────

    public function get(string $uri, array $query = []): RequestBuilder
    {
        $builder = $this->builder('GET', $uri);

        if (!empty($query)) {
            $builder->withQuery($query);
        }

        return $builder;
    }

    public function post(string $uri): RequestBuilder
    {
        return $this->builder('POST', $uri);
    }

    public function put(string $uri): RequestBuilder
    {
        return $this->builder('PUT', $uri);
    }

    public function patch(string $uri): RequestBuilder
    {
        return $this->builder('PATCH', $uri);
    }

    public function delete(string $uri): RequestBuilder
    {
        return $this->builder('DELETE', $uri);
    }

    public function head(string $uri): RequestBuilder
    {
        return $this->builder('HEAD', $uri);
    }

    public function request(string $method, string $uri): RequestBuilder
    {
        return $this->builder(strtoupper($method), $uri);
    }

    // ─── Event Listener Shortcuts ─────────────────────────────────────────────

    /**
     * Registers a listener on the client's EventDispatcher.
     *
     * @template T of \WireHttp\Event\WireEventInterface
     * @param class-string<T> $eventClass
     */
    public function on(string $eventClass, callable $listener, int $priority = 0): static
    {
        $this->events->listen($eventClass, $listener, $priority);

        return $this;
    }

    /**
     * Returns the client's EventDispatcher for advanced listener management.
     */
    public function getDispatcher(): EventDispatcher
    {
        return $this->events;
    }

    /**
     * Returns the active transport (useful for debugging or testing).
     */
    public function getTransport(): TransportInterface
    {
        return $this->transport;
    }

    /**
     * Returns the middleware stack.
     */
    public function getStack(): MiddlewareStack
    {
        return $this->stack;
    }

    // ─── Private: RequestBuilder Factory ─────────────────────────────────────

    private function builder(string $method, string $uri): RequestBuilder
    {
        $fullUri = $this->resolveUri($uri);

        $sender = function (Request $request): ResponseDecorator {
            $response = $this->sendRequest($request);

            return new ResponseDecorator($response, $this->hydrator);
        };

        $asyncSender = function (Request $request): Future {
            return $this->sendRequestAsync($request)->map(
                fn(Response $response) => new ResponseDecorator($response, $this->hydrator)
            );
        };

        return new RequestBuilder(
            method: $method,
            uri: $fullUri,
            sender: \Closure::fromCallable($sender),
            asyncSender: \Closure::fromCallable($asyncSender),
            defaultHeaders: $this->config->defaultHeaders,
        );
    }

    // ─── Private: Transport Resolution ────────────────────────────────────────

    /**
     * Selects the best available transport in priority order.
     */
    private function resolveTransport(): TransportInterface
    {
        $multi = new CurlMultiHandler();

        if ($multi->isAvailable()) {
            return $multi;
        }

        $curl = new CurlTransport();

        if ($curl->isAvailable()) {
            return $curl;
        }

        $stream = new StreamTransport();

        if ($stream->isAvailable()) {
            return $stream;
        }

        throw new \RuntimeException(
            'WireHTTP: No HTTP transport is available. ' .
            'Enable ext-curl or set allow_url_fopen=On in php.ini.'
        );
    }

    // ─── Private: Middleware Stack Assembly ────────────────────────────────────

    private function buildMiddlewareStack(array $extraMiddleware): MiddlewareStack
    {
        $stack = new MiddlewareStack($this->transport);

        // Redirect following (must wrap cookies so redirected requests get injected cookies)
        if ($this->config->followRedirects) {
            $stack->push(new RedirectInterceptor($this->config->maxRedirects));
        }

        // Cookie management (innermost — closest to transport)
        if ($this->config->manageCookies) {
            $jar = $this->config->cookieJar ?? new CookieJar();
            $stack->push(new CookieInterceptor($jar));
        }

        // Retry on transient failure
        if ($this->config->maxRetries > 0) {
            $stack->push(new RetryInterceptor(
                maxAttempts: $this->config->maxRetries,
                baseDelaySeconds: $this->config->retryDelaySeconds,
                maxDelaySeconds: $this->config->maxRetryDelaySeconds,
            ));
        }

        // Any additional middleware passed to the constructor (outermost)
        foreach (array_reverse($extraMiddleware) as $middleware) {
            $stack->prepend($middleware);
        }

        return $stack;
    }

    // ─── Private: Helpers ─────────────────────────────────────────────────────

    /**
     * Resolves a relative URI against the configured base URI.
     */
    private function resolveUri(string $uri): string
    {
        if ($this->config->baseUri === null || str_contains($uri, '://')) {
            return $uri;
        }

        return rtrim($this->config->baseUri, '/') . '/' . ltrim($uri, '/');
    }

    /**
     * Merges client-level default headers into the request without overriding
     * headers already explicitly set on the request.
     */
    private function applyDefaultHeaders(Request $request): Request
    {
        // Apply User-Agent if not set
        if (!$request->hasHeader('User-Agent')) {
            $request = $request->withHeader('User-Agent', $this->config->userAgent);
        }

        // Apply default headers from config (without overriding per-request headers)
        foreach ($this->config->defaultHeaders as $name => $value) {
            if (!$request->hasHeader($name)) {
                $request = $request->withHeader($name, $value);
            }
        }

        // Apply basic auth if configured at client level (and not overridden per-request)
        if ($this->config->basicAuth !== null && !$request->hasHeader('Authorization')) {
            [$user, $pass] = $this->config->basicAuth;
            $request = $request->withHeader(
                'Authorization',
                'Basic ' . base64_encode("{$user}:{$pass}")
            );
        }

        return $request;
    }
}
