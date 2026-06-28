<?php

declare(strict_types=1);

namespace WireHttp\Tests;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use WireHttp\Http\Response;
use WireHttp\Http\Stream;
use WireHttp\Transport\Mock\MockResponseQueue;
use WireHttp\Transport\Mock\MockTransport;
use WireHttp\Wire;

/**
 * WireHTTP Base Test Case
 *
 * Provides shared helpers for all WireHTTP tests:
 *   - Wire::fake() setup and teardown
 *   - Response factory shortcuts
 *   - JSON assertion helpers
 */
abstract class TestCase extends PhpUnitTestCase
{
    protected MockTransport $mockTransport;
    protected MockResponseQueue $mockQueue;

    /**
     * Installs a MockTransport into the global Wire facade before each test.
     * The mock queue starts empty — populate it in individual tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockQueue     = new MockResponseQueue();
        $this->mockTransport = Wire::fake($this->mockQueue);
    }

    /**
     * Restores the real Wire client after each test.
     */
    protected function tearDown(): void
    {
        Wire::restoreFake();

        parent::tearDown();
    }

    // ─── Response Factory Helpers ──────────────────────────────────────────────

    /**
     * Creates a 200 OK response with a JSON body.
     *
     * @param array<mixed> $data
     */
    protected function jsonResponse(array $data, int $status = 200): Response
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return new Response($status, [
            'Content-Type'   => ['application/json; charset=utf-8'],
            'Content-Length' => [(string) strlen($encoded)],
        ], Stream::fromString($encoded));
    }

    /**
     * Creates a plain-text response.
     */
    protected function textResponse(string $body, int $status = 200): Response
    {
        return new Response($status, [
            'Content-Type'   => ['text/plain; charset=utf-8'],
            'Content-Length' => [(string) strlen($body)],
        ], Stream::fromString($body));
    }

    /**
     * Creates an empty response (e.g., 204 No Content).
     */
    protected function emptyResponse(int $status = 204): Response
    {
        return new Response($status, []);
    }

    /**
     * Creates a redirect response.
     */
    protected function redirectResponse(string $location, int $status = 302): Response
    {
        return new Response($status, ['Location' => [$location]], Stream::fromString(''));
    }

    /**
     * Creates a 500 Internal Server Error response.
     */
    protected function serverErrorResponse(string $body = 'Internal Server Error'): Response
    {
        return new Response(500, [
            'Content-Type' => ['text/plain'],
        ], Stream::fromString($body));
    }

    /**
     * Pushes a JSON response onto the mock queue.
     *
     * @param array<mixed> $data
     */
    protected function queueJson(array $data, int $status = 200): void
    {
        $this->mockQueue->push($this->jsonResponse($data, $status));
    }

    /**
     * Pushes an exception onto the mock queue (simulates network failure).
     */
    protected function queueException(\Throwable $exception): void
    {
        $this->mockQueue->push($exception);
    }

    // ─── Custom Assertions ─────────────────────────────────────────────────────

    /**
     * Asserts that the nth request (0-indexed) used the given HTTP method.
     */
    protected function assertRequestMethod(int $index, string $expectedMethod): void
    {
        $this->mockQueue->assertRequestMethod($index, $expectedMethod);
    }

    /**
     * Asserts that the nth request targeted the given URL.
     */
    protected function assertRequestUrl(int $index, string $expectedUrl): void
    {
        $this->mockQueue->assertRequestUrl($index, $expectedUrl);
    }

    /**
     * Asserts the total number of requests made through the mock.
     */
    protected function assertRequestCount(int $count): void
    {
        $this->mockQueue->assertRequestCount($count);
    }

    /**
     * Asserts that the nth request carried a specific header value.
     */
    protected function assertRequestHasHeader(int $index, string $name, string $expected): void
    {
        $history = $this->mockQueue->getRequestHistory();

        $this->assertArrayHasKey(
            $index,
            $history,
            sprintf('No request at index %d (total: %d).', $index, count($history))
        );

        $actual = $history[$index]->getHeaderLine($name);

        $this->assertSame(
            $expected,
            $actual,
            sprintf('Request #%d header "%s" mismatch.', $index, $name)
        );
    }

    /**
     * Asserts that the nth request body decodes to the given JSON array.
     *
     * @param array<mixed> $expected
     */
    protected function assertRequestJson(int $index, array $expected): void
    {
        $history = $this->mockQueue->getRequestHistory();

        $this->assertArrayHasKey($index, $history);

        $body  = $history[$index]->getBody();
        $body->rewind();
        $json  = json_decode($body->getContents(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame($expected, $json);
    }
}
