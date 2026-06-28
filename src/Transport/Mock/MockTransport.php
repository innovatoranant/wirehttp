<?php

declare(strict_types=1);

namespace WireHttp\Transport\Mock;

use WireHttp\Async\Future;
use WireHttp\Http\Request;
use WireHttp\Http\Response;
use WireHttp\Transport\TransportInterface;

/**
 * MockTransport — Fake HTTP Transport for Unit Testing
 *
 * MockTransport replaces the real network layer in tests. Instead of sending
 * actual HTTP requests, it dequeues pre-configured fake Responses (or throws
 * pre-configured Exceptions) from a `MockResponseQueue`.
 *
 * This allows you to test HTTP-dependent code without:
 *  - Hitting real APIs (flaky, slow, rate-limited)
 *  - Running a local server or WireMock
 *  - Mocking the cURL extension (fragile)
 *
 * Usage:
 *   $queue = new MockResponseQueue();
 *   $queue->push(MockResponseQueue::withJson(['id' => 1, 'name' => 'Alice']));
 *
 *   $client   = new Client(transport: new MockTransport($queue));
 *   $response = $client->get('https://api.example.com/users/1');
 *
 *   $response->isOk();                    // true
 *   $response->json()['name'];            // 'Alice'
 *   $queue->assertRequestCount(1);        // passes
 *   $queue->assertRequestUrl(0, 'https://api.example.com/users/1'); // passes
 *
 * Dynamic Response via Closure:
 *   $queue->push(function(Request $request): Response {
 *       if ($request->getUri()->getPath() === '/users') {
 *           return MockResponseQueue::buildJsonResponse([['id' => 1]]);
 *       }
 *       return new Response(404);
 *   });
 *
 * Simulating Network Failure:
 *   $queue->push(new TimeoutException(connectTimeout: true, configuredTimeoutSeconds: 5.0));
 *   $client->get('/api'); // throws TimeoutException
 *
 * Latency Simulation:
 *   $transport = new MockTransport($queue, latencyMs: 50); // adds 50ms artificial delay
 */
final class MockTransport implements TransportInterface
{
    private readonly MockResponseQueue $queue;

    /**
     * Artificial latency in milliseconds to add to each response.
     * Simulates real network round-trip time in slow tests.
     * 0 = no delay (default).
     */
    private readonly int $latencyMs;

    /**
     * Whether this transport is "available". You can set to false to test
     * fallback transport selection logic.
     */
    private readonly bool $available;

    public function __construct(
        ?MockResponseQueue $queue = null,
        int $latencyMs = 0,
        bool $available = true,
    ) {
        $this->queue     = $queue ?? new MockResponseQueue();
        $this->latencyMs = $latencyMs;
        $this->available = $available;
    }

    // ─── TransportInterface ───────────────────────────────────────────────────

    /**
     * Dequeues the next entry from the MockResponseQueue and returns it.
     * If the entry is a Throwable, it is thrown directly.
     * If the entry is a Closure, it is called with the Request.
     */
    public function send(Request $request): Response
    {
        // Simulate network latency if configured
        if ($this->latencyMs > 0) {
            usleep($this->latencyMs * 1000);
        }

        $entry = $this->queue->dequeue($request);

        if ($entry instanceof \Throwable) {
            throw $entry;
        }

        if ($entry instanceof \Closure) {
            $result = $entry($request);

            if ($result instanceof \Throwable) {
                throw $result;
            }

            if (!($result instanceof Response)) {
                throw new \LogicException(
                    sprintf(
                        'MockTransport closure must return a Response or Throwable, got %s.',
                        get_debug_type($result)
                    )
                );
            }

            return $result;
        }

        return $entry;
    }

    /**
     * Async version — wraps send() in a resolved/rejected Future.
     * MockTransport does not support true concurrency; all requests are sequential.
     *
     * @return Future<Response>
     */
    public function sendAsync(Request $request): Future
    {
        try {
            return Future::resolved($this->send($request));
        } catch (\Throwable $e) {
            return Future::rejected($e);
        }
    }

    /**
     * Returns whether this mock transport is configured as "available".
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Returns a human-readable name for diagnostics.
     */
    public function name(): string
    {
        return 'mock';
    }

    // ─── Queue Passthrough (convenience) ──────────────────────────────────────

    /**
     * Returns the underlying MockResponseQueue for assertion and population.
     */
    public function getQueue(): MockResponseQueue
    {
        return $this->queue;
    }

    /**
     * Pushes a response onto the queue (convenience passthrough).
     */
    public function push(Response|\Throwable|\Closure $entry): static
    {
        $this->queue->push($entry);

        return $this;
    }

    /**
     * Returns the number of requests that have been processed.
     */
    public function getRequestCount(): int
    {
        return $this->queue->getRequestCount();
    }

    /**
     * Returns the last request processed by this transport.
     */
    public function getLastRequest(): ?Request
    {
        return $this->queue->getLastRequest();
    }

    /**
     * Returns the full request history.
     *
     * @return list<Request>
     */
    public function getRequestHistory(): array
    {
        return $this->queue->getRequestHistory();
    }

    /**
     * Assert that a specific number of requests were made.
     *
     * @throws \AssertionError
     */
    public function assertRequestCount(int $expected): void
    {
        $this->queue->assertRequestCount($expected);
    }

    /**
     * Assert that the nth request targeted the given URL.
     *
     * @throws \AssertionError
     */
    public function assertRequestUrl(int $index, string $url): void
    {
        $this->queue->assertRequestUrl($index, $url);
    }

    /**
     * Assert that the nth request used the given HTTP method.
     *
     * @throws \AssertionError
     */
    public function assertRequestMethod(int $index, string $method): void
    {
        $this->queue->assertRequestMethod($index, $method);
    }
}
