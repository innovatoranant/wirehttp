<?php

declare(strict_types=1);

namespace WireHttp\Request\Builder;

use WireHttp\Async\Future;
use WireHttp\Configuration\TimeoutConfig;
use WireHttp\Configuration\SslConfig;
use WireHttp\Enums\HttpMethod;
use WireHttp\Http\Request;
use WireHttp\Http\Stream;
use WireHttp\Http\Uri;
use WireHttp\Request\Payload\FormPayload;
use WireHttp\Request\Payload\JsonPayload;
use WireHttp\Request\Payload\MultipartPayload;
use WireHttp\Request\Signatures\HmacSigner;
use WireHttp\Response\ResponseDecorator;

/**
 * RequestBuilder — The Fluent HTTP Request Construction Interface
 *
 * RequestBuilder is the central developer-facing API in WireHTTP. It wraps
 * a mutable (but internally immutable-modeled) request state and provides
 * a rich, chainable API for building and sending HTTP requests.
 *
 * Returned by every entry point on the `Client` and `Wire` facade:
 *   Wire::get('/users')       → RequestBuilder
 *   Wire::post('/users')      → RequestBuilder
 *   $client->patch('/item/1') → RequestBuilder
 *
 * The builder is "lazy" — no network I/O occurs until `send()` or `sendAsync()`
 * is called. All chained methods mutate the builder's internal state and
 * return `$this` for fluent chaining.
 *
 * Complete Usage Example:
 * -----------------------
 *   $response = Wire::post('https://api.example.com/users')
 *       ->accept('application/json')
 *       ->withBearer($token)
 *       ->withJson(['name' => 'Alice', 'role' => 'admin'])
 *       ->timeout(15.0)
 *       ->retry(3)
 *       ->throw()   // ← throws on 4xx/5xx
 *       ->send();
 *
 *   $user = $response->into(UserDto::class);
 *
 * Async Example:
 * --------------
 *   $future  = Wire::get('/users')->sendAsync();
 *   $future2 = Wire::get('/posts')->sendAsync();
 *
 *   [$users, $posts] = Future::all($future, $future2);
 */
final class RequestBuilder
{
    // ─── Internal State ───────────────────────────────────────────────────────

    private string  $method  = 'GET';
    private string  $uri;
    private array   $headers = [];
    private ?Stream $body    = null;
    private array   $query   = [];
    private array   $options = [];

    /**
     * Whether to call throw() on the response automatically (throws on 4xx/5xx).
     */
    private bool $autoThrow = false;

    /**
     * The callable that sends the finalized Request (injected by Client).
     * Signature: callable(Request): ResponseDecorator
     */
    private readonly \Closure $sender;

    /**
     * The callable for async sending.
     * Signature: callable(Request): Future<ResponseDecorator>
     */
    private readonly \Closure $asyncSender;

    public function __construct(
        string   $method,
        string   $uri,
        \Closure $sender,
        \Closure $asyncSender,
        array    $defaultHeaders = [],
    ) {
        $this->method      = strtoupper($method);
        $this->uri         = $uri;
        $this->sender      = $sender;
        $this->asyncSender = $asyncSender;
        $this->headers     = $defaultHeaders;
    }

    // ─── URI & Query ─────────────────────────────────────────────────────────

    /**
     * Appends query string parameters to the URI.
     *
     * @param array<string, mixed> $params
     */
    public function withQuery(array $params): static
    {
        $this->query = array_merge($this->query, $params);

        return $this;
    }

    /**
     * Adds a single query parameter.
     */
    public function withParam(string $key, mixed $value): static
    {
        $this->query[$key] = $value;

        return $this;
    }

    // ─── Headers ─────────────────────────────────────────────────────────────

    /**
     * Sets (or replaces) a single request header.
     */
    public function withHeader(string $name, string $value): static
    {
        $this->headers[strtolower($name)] = $value;

        return $this;
    }

    /**
     * Sets multiple headers at once.
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->withHeader($name, $value);
        }

        return $this;
    }

    /**
     * Sets the `Accept` header.
     */
    public function accept(string $mediaType = 'application/json'): static
    {
        return $this->withHeader('Accept', $mediaType);
    }

    /**
     * Sets the `Content-Type` header.
     */
    public function contentType(string $mediaType): static
    {
        return $this->withHeader('Content-Type', $mediaType);
    }

    /**
     * Sets the `User-Agent` header.
     */
    public function userAgent(string $ua): static
    {
        return $this->withHeader('User-Agent', $ua);
    }

    /**
     * Sets a `Referer` header.
     */
    public function referer(string $url): static
    {
        return $this->withHeader('Referer', $url);
    }

    // ─── Authentication ───────────────────────────────────────────────────────

    /**
     * Adds a Bearer token `Authorization` header.
     */
    public function withBearer(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token);
    }

    /**
     * Adds HTTP Basic authentication via `Authorization` header.
     */
    public function withBasicAuth(string $username, string $password): static
    {
        return $this->withHeader(
            'Authorization',
            'Basic ' . base64_encode("{$username}:{$password}")
        );
    }

    /**
     * Adds a custom Authorization header (e.g., API keys, AWS SigV4, etc.).
     */
    public function withAuth(string $scheme, string $credentials): static
    {
        return $this->withHeader('Authorization', "{$scheme} {$credentials}");
    }

    /**
     * Signs the request with the given HmacSigner before sending.
     * The signing happens at send-time to capture the correct body hash.
     */
    public function withSignature(HmacSigner $signer): static
    {
        $this->options['__hmac_signer'] = $signer;

        return $this;
    }

    // ─── Body / Payload ───────────────────────────────────────────────────────

    /**
     * Sets the request body as a raw string.
     */
    public function withBody(string $body, string $contentType = 'text/plain'): static
    {
        $this->body = Stream::fromString($body);
        $this->withHeader('Content-Type', $contentType);
        $this->withHeader('Content-Length', (string) strlen($body));

        return $this;
    }

    /**
     * Sets the request body as a JSON-encoded payload.
     * Automatically sets Content-Type: application/json.
     *
     * @param mixed $data Any JSON-serializable value.
     */
    public function withJson(mixed $data): static
    {
        [$stream, $contentType, $length] = JsonPayload::encode($data);
        $this->body = $stream;
        $this->withHeader('Content-Type', $contentType);
        $this->withHeader('Content-Length', (string) $length);

        return $this;
    }

    /**
     * Sets the request body as URL-encoded form data.
     * Automatically sets Content-Type: application/x-www-form-urlencoded.
     *
     * @param array<string, mixed> $data Form fields.
     */
    public function withForm(array $data): static
    {
        [$stream, $contentType, $length] = FormPayload::encode($data);
        $this->body = $stream;
        $this->withHeader('Content-Type', $contentType);
        $this->withHeader('Content-Length', (string) $length);

        return $this;
    }

    /**
     * Sets the request body as a multipart/form-data payload.
     * Automatically sets Content-Type with the boundary string.
     *
     * @param list<array<string, mixed>> $parts
     */
    public function withMultipart(array $parts): static
    {
        [$stream, $contentType, $length] = MultipartPayload::encode($parts);
        $this->body = $stream;
        $this->withHeader('Content-Type', $contentType);
        $this->withHeader('Content-Length', (string) $length);

        return $this;
    }

    /**
     * Sets the request body from a PHP resource or Stream.
     */
    public function withStream(mixed $resource, ?string $contentType = null): static
    {
        $this->body = $resource instanceof Stream
            ? $resource
            : Stream::fromResource($resource);

        if ($contentType !== null) {
            $this->withHeader('Content-Type', $contentType);
        }

        return $this;
    }

    // ─── Request Options ─────────────────────────────────────────────────────

    /**
     * Sets the total request timeout in seconds.
     */
    public function timeout(float $seconds): static
    {
        $this->options['timeout'] = new TimeoutConfig(
            connectSeconds: min($seconds, 10.0),
            requestSeconds: $seconds,
        );

        return $this;
    }

    /**
     * Sets both connect and request timeouts independently.
     */
    public function withTimeout(TimeoutConfig $timeout): static
    {
        $this->options['timeout'] = $timeout;

        return $this;
    }

    /**
     * Configures SSL settings for this specific request.
     */
    public function withSsl(SslConfig $ssl): static
    {
        $this->options['ssl'] = $ssl;

        return $this;
    }

    /**
     * Configures automatic retry for this request.
     *
     * @param int   $times           Maximum total attempts (1 = no retry).
     * @param float $baseDelay       Base delay in seconds (exponential backoff base).
     */
    public function retry(int $times = 3, float $baseDelay = 0.5): static
    {
        $this->options['retry'] = [
            'max_attempts'   => $times,
            'base_delay'     => $baseDelay,
        ];

        return $this;
    }

    /**
     * Sets an arbitrary transport-level option (passed through to cURL).
     */
    public function withOption(string $key, mixed $value): static
    {
        $this->options[$key] = $value;

        return $this;
    }

    // ─── Behavior Modifiers ───────────────────────────────────────────────────

    /**
     * Configures the response to automatically throw on 4xx/5xx.
     * When set, calling `send()` will throw immediately on HTTP errors
     * rather than returning the error response.
     */
    public function throw(): static
    {
        $this->autoThrow = true;

        return $this;
    }

    // ─── Sending ─────────────────────────────────────────────────────────────

    /**
     * Builds the final Request and sends it synchronously.
     * Returns a ResponseDecorator wrapping the HTTP response.
     *
     * @throws \WireHttp\Exceptions\WireHttpException On any network or HTTP error.
     * @throws \WireHttp\Exceptions\HttpClientException If throw() was chained and a 4xx was received.
     * @throws \WireHttp\Exceptions\HttpServerException If throw() was chained and a 5xx was received.
     */
    public function send(): ResponseDecorator
    {
        $request  = $this->buildRequest();
        $response = ($this->sender)($request);

        if ($this->autoThrow) {
            $response->throw();
        }

        return $response;
    }

    /**
     * Builds the final Request and sends it asynchronously.
     * Returns a Future that resolves to a ResponseDecorator.
     *
     * @return Future<ResponseDecorator>
     */
    public function sendAsync(): Future
    {
        $request     = $this->buildRequest();
        $autoThrow   = $this->autoThrow;
        $future      = ($this->asyncSender)($request);

        if ($autoThrow) {
            return $future->map(static function (ResponseDecorator $response): ResponseDecorator {
                $response->throw();

                return $response;
            });
        }

        return $future;
    }

    // ─── Private: Request Construction ────────────────────────────────────────

    /**
     * Assembles the final immutable Request from the builder's current state.
     */
    private function buildRequest(): Request
    {
        $uri = $this->buildUri();

        // Build PSR-7 headers (array<string, list<string>>)
        $headers = [];

        foreach ($this->headers as $name => $value) {
            $headers[$name] = [$value];
        }

        $body = $this->body ?? Stream::empty();

        $request = new Request(
            method: $this->method,
            uri: $uri,
            headers: $headers,
            body: $body,
            options: $this->options,
        );

        // Apply HMAC signing if configured
        $signer = $this->options['__hmac_signer'] ?? null;

        if ($signer instanceof HmacSigner) {
            $request = $signer->sign($request);
        }

        return $request;
    }

    /**
     * Merges the query parameters into the URI.
     */
    private function buildUri(): Uri
    {
        $uri = new Uri($this->uri);

        if (!empty($this->query)) {
            $existing  = $uri->getQuery();
            $existing  = $existing !== '' ? $existing . '&' : '';
            $queryStr  = $existing . http_build_query($this->query, encoding_type: PHP_QUERY_RFC3986);
            $uri       = $uri->withQuery($queryStr);
        }

        return $uri;
    }

    // ─── HTTP Method Shortcuts ────────────────────────────────────────────────

    /**
     * Changes the request method to GET. Useful when a builder was
     * created generically without specifying a method.
     */
    public function get(): static
    {
        $this->method = 'GET';

        return $this;
    }

    public function post(): static
    {
        $this->method = 'POST';

        return $this;
    }

    public function put(): static
    {
        $this->method = 'PUT';

        return $this;
    }

    public function patch(): static
    {
        $this->method = 'PATCH';

        return $this;
    }

    public function delete(): static
    {
        $this->method = 'DELETE';

        return $this;
    }

    public function head(): static
    {
        $this->method = 'HEAD';

        return $this;
    }
}
