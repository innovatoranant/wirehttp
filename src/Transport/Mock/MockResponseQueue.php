<?php

declare(strict_types=1);

namespace WireHttp\Transport\Mock;

use WireHttp\Http\Response;
use WireHttp\Http\Stream;

/**
 * MockResponseQueue — A Typed, Thread-Safe Queue of Fake HTTP Responses
 *
 * This is the backing store for MockTransport. It holds a FIFO queue of
 * pre-configured fake responses (or exceptions) that MockTransport dequeues
 * one by one for each incoming request.
 *
 * Design:
 * -------
 * Each entry in the queue is either:
 *   - A `Response` object (returned as-is for the next request).
 *   - A `\Throwable` (thrown as-is for the next request, simulating a network failure).
 *   - A `\Closure` (called with the Request and must return a Response or throw).
 *
 * This flexibility allows testing all possible scenarios:
 *   - Simple success responses: `MockResponseQueue::withJson(['users' => [...]])`
 *   - Network failures:         `MockResponseQueue::withException(new TimeoutException(...))`
 *   - Dynamic responses:        `MockResponseQueue::withCallback(fn($req) => ...)`
 *   - Sequential scenarios:     Queue multiple entries, consumed in order.
 *
 * Overflow Behavior:
 * ------------------
 * By default, if more requests are made than responses are queued, the queue
 * throws an `\UnderflowException`. You can change this to "repeat the last
 * response" mode via `setRepeatLast(true)`.
 *
 * Usage:
 *   $queue = new MockResponseQueue();
 *   $queue->push(MockResponseQueue::ok(['users' => []]));
 *   $queue->push(new TimeoutException(...));
 *   $queue->push(fn(Request $r) => new Response(200));
 *
 *   $transport = new MockTransport($queue);
 *   $client    = new Client(transport: $transport);
 */
final class MockResponseQueue
{
    /**
     * The FIFO queue of entries. Each entry is a Response, Throwable, or Closure.
     *
     * @var list<Response|\Throwable|\Closure>
     */
    private array $queue = [];

    /**
     * A record of all requests that have been processed by this queue.
     * Used for assertions in tests.
     *
     * @var list<\WireHttp\Http\Request>
     */
    private array $requestHistory = [];

    /**
     * Whether to repeat the last queued entry when the queue runs out.
     * Default: false (throws UnderflowException on empty queue).
     */
    private bool $repeatLast = false;

    /**
     * The last entry dequeued, used for repeatLast mode.
     */
    private Response|\Throwable|\Closure|null $lastEntry = null;

    // ─── Queue Population ─────────────────────────────────────────────────────

    /**
     * Appends a response, exception, or dynamic callback to the queue.
     *
     * @param Response|\Throwable|\Closure $entry
     */
    public function push(Response|\Throwable|\Closure $entry): static
    {
        $this->queue[] = $entry;

        return $this;
    }

    /**
     * Prepends a response to the front of the queue.
     * Useful for injecting an emergency response without clearing the queue.
     */
    public function prepend(Response|\Throwable|\Closure $entry): static
    {
        array_unshift($this->queue, $entry);

        return $this;
    }

    /**
     * Clears the entire queue.
     */
    public function clear(): static
    {
        $this->queue     = [];
        $this->lastEntry = null;

        return $this;
    }

    /**
     * If true, the last queued response is repeated indefinitely when the queue
     * runs out, rather than throwing an UnderflowException.
     */
    public function setRepeatLast(bool $repeat = true): static
    {
        $this->repeatLast = $repeat;

        return $this;
    }

    // ─── Static Factory Shortcuts ─────────────────────────────────────────────

    /**
     * Creates a queue pre-populated with a single 200 OK JSON response.
     *
     * @param array<mixed> $data
     */
    public static function withJson(array $data, int $status = 200): static
    {
        $queue = new static();
        $queue->push(static::buildJsonResponse($data, $status));

        return $queue;
    }

    /**
     * Creates a queue pre-populated with a single throwable (network error simulation).
     */
    public static function withException(\Throwable $exception): static
    {
        $queue = new static();
        $queue->push($exception);

        return $queue;
    }

    /**
     * Creates a queue pre-populated with a single dynamic callback.
     */
    public static function withCallback(\Closure $callback): static
    {
        $queue = new static();
        $queue->push($callback);

        return $queue;
    }

    /**
     * Creates a queue pre-populated with a plain 200 OK response.
     */
    public static function withOk(string $body = '', string $contentType = 'text/plain'): static
    {
        $queue = new static();
        $queue->push(new Response(200, [
            'Content-Type'   => $contentType,
            'Content-Length' => (string) strlen($body),
        ], Stream::fromString($body)));

        return $queue;
    }

    /**
     * Creates a queue pre-populated with a 500 Internal Server Error.
     */
    public static function withServerError(string $body = 'Internal Server Error'): static
    {
        $queue = new static();
        $queue->push(new Response(500, [], Stream::fromString($body)));

        return $queue;
    }

    /**
     * Creates a queue pre-populated with a 404 Not Found.
     */
    public static function withNotFound(string $body = 'Not Found'): static
    {
        $queue = new static();
        $queue->push(new Response(404, [], Stream::fromString($body)));

        return $queue;
    }

    /**
     * Creates a queue pre-populated with a redirect response.
     */
    public static function withRedirect(string $location, int $status = 302): static
    {
        $queue = new static();
        $queue->push(new Response($status, ['Location' => $location]));

        return $queue;
    }

    // ─── Dequeue (used by MockTransport) ──────────────────────────────────────

    /**
     * Dequeues and returns the next entry from the queue.
     * Records the given request in the history.
     *
     * @param \WireHttp\Http\Request $request The request being processed.
     * @return Response|\Throwable|\Closure
     * @throws \UnderflowException if the queue is empty and repeatLast is false.
     */
    public function dequeue(\WireHttp\Http\Request $request): Response|\Throwable|\Closure
    {
        $this->requestHistory[] = $request;

        if (!empty($this->queue)) {
            $entry           = array_shift($this->queue);
            $this->lastEntry = $entry;

            return $entry;
        }

        if ($this->repeatLast && $this->lastEntry !== null) {
            return $this->lastEntry;
        }

        throw new \UnderflowException(
            sprintf(
                'MockResponseQueue is empty. You made %d request(s) but only queued %d response(s). ' .
                'Add more responses via $queue->push(...) or enable $queue->setRepeatLast(true).',
                count($this->requestHistory),
                count($this->requestHistory) - 1
            )
        );
    }

    // ─── History & Assertions ─────────────────────────────────────────────────

    /**
     * Returns all requests that have been processed by this queue, in order.
     *
     * @return list<\WireHttp\Http\Request>
     */
    public function getRequestHistory(): array
    {
        return $this->requestHistory;
    }

    /**
     * Returns the most recent request processed by this queue.
     * Returns null if no requests have been processed yet.
     */
    public function getLastRequest(): ?\WireHttp\Http\Request
    {
        return empty($this->requestHistory)
            ? null
            : $this->requestHistory[count($this->requestHistory) - 1];
    }

    /**
     * Returns the number of requests that have been processed.
     */
    public function getRequestCount(): int
    {
        return count($this->requestHistory);
    }

    /**
     * Returns the number of responses remaining in the queue.
     */
    public function remaining(): int
    {
        return count($this->queue);
    }

    /**
     * Returns true if the queue is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->queue);
    }

    /**
     * Asserts that exactly $count requests were made. Throws if not.
     *
     * @throws \AssertionError if the count does not match.
     */
    public function assertRequestCount(int $count): void
    {
        $actual = $this->getRequestCount();

        if ($actual !== $count) {
            throw new \AssertionError(
                sprintf(
                    'Expected %d request(s) to be made, but %d were made.',
                    $count,
                    $actual
                )
            );
        }
    }

    /**
     * Asserts that the nth request (0-indexed) was made to the given URL.
     *
     * @throws \AssertionError if the URL does not match.
     * @throws \OutOfBoundsException if the index is out of range.
     */
    public function assertRequestUrl(int $index, string $expectedUrl): void
    {
        if (!isset($this->requestHistory[$index])) {
            throw new \OutOfBoundsException(
                sprintf('No request at index %d (total: %d).', $index, count($this->requestHistory))
            );
        }

        $actual = (string) $this->requestHistory[$index]->getUri();

        if ($actual !== $expectedUrl) {
            throw new \AssertionError(
                sprintf('Request #%d URL mismatch. Expected "%s", got "%s".', $index, $expectedUrl, $actual)
            );
        }
    }

    /**
     * Asserts that the nth request used the given HTTP method.
     *
     * @throws \AssertionError if the method does not match.
     */
    public function assertRequestMethod(int $index, string $expectedMethod): void
    {
        if (!isset($this->requestHistory[$index])) {
            throw new \OutOfBoundsException(
                sprintf('No request at index %d.', $index)
            );
        }

        $actual = $this->requestHistory[$index]->getMethod();

        if (strtoupper($actual) !== strtoupper($expectedMethod)) {
            throw new \AssertionError(
                sprintf(
                    'Request #%d method mismatch. Expected "%s", got "%s".',
                    $index,
                    strtoupper($expectedMethod),
                    strtoupper($actual)
                )
            );
        }
    }

    // ─── Private Helpers ─────────────────────────────────────────────────────

    private static function buildJsonResponse(array $data, int $status): Response
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new Response($status, [
            'Content-Type'   => 'application/json; charset=utf-8',
            'Content-Length' => (string) strlen($encoded),
        ], Stream::fromString($encoded));
    }
}
