<?php

declare(strict_types=1);

namespace WireHttp\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use WireHttp\Exceptions\NetworkException;
use WireHttp\Http\Request;
use WireHttp\Http\Response;
use WireHttp\Http\Stream;
use WireHttp\Middleware\Core\RetryInterceptor;

final class RetryInterceptorTest extends TestCase
{
    private function makeRequest(): Request
    {
        return new Request('GET', 'https://api.example.com/data');
    }

    private function makeResponse(int $status, array $headers = []): Response
    {
        return new Response($status, $headers, Stream::fromString(''));
    }

    // ─── Passthrough on Success ────────────────────────────────────────────────

    public function test_passes_through_successful_response(): void
    {
        $interceptor = new RetryInterceptor(maxAttempts: 3);
        $attempts    = 0;

        $response = $interceptor->process(
            $this->makeRequest(),
            function (Request $r) use (&$attempts): Response {
                $attempts++;

                return $this->makeResponse(200);
            }
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $attempts);
    }

    // ─── Retry on 5xx ────────────────────────────────────────────────────────

    public function test_retries_on_500_server_error(): void
    {
        $interceptor = new RetryInterceptor(maxAttempts: 3, baseDelaySeconds: 0.0);
        $attempts    = 0;

        $response = $interceptor->process(
            $this->makeRequest(),
            function (Request $r) use (&$attempts): Response {
                $attempts++;

                // Fail twice, then succeed
                return $attempts < 3
                    ? $this->makeResponse(500)
                    : $this->makeResponse(200);
            }
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(3, $attempts);
    }

    public function test_retries_on_503_service_unavailable(): void
    {
        $interceptor = new RetryInterceptor(maxAttempts: 2, baseDelaySeconds: 0.0);
        $attempts    = 0;

        $response = $interceptor->process(
            $this->makeRequest(),
            function (Request $r) use (&$attempts): Response {
                $attempts++;

                return $attempts === 1
                    ? $this->makeResponse(503)
                    : $this->makeResponse(200);
            }
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $attempts);
    }

    // ─── Retry on Network Exception ────────────────────────────────────────────

    public function test_retries_on_network_exception(): void
    {
        $interceptor = new RetryInterceptor(maxAttempts: 3, baseDelaySeconds: 0.0);
        $attempts    = 0;
        $request     = $this->makeRequest();

        $response = $interceptor->process(
            $request,
            function (Request $r) use (&$attempts, $request): Response {
                $attempts++;

                if ($attempts < 3) {
                    throw new NetworkException('Connection refused', 0, null, $request);
                }

                return $this->makeResponse(200);
            }
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(3, $attempts);
    }

    // ─── Exhaust All Attempts ─────────────────────────────────────────────────

    public function test_throws_after_exhausting_all_attempts(): void
    {
        $interceptor = new RetryInterceptor(maxAttempts: 2, baseDelaySeconds: 0.0);
        $request     = $this->makeRequest();

        $this->expectException(NetworkException::class);

        $interceptor->process(
            $request,
            function (Request $r) use ($request): Response {
                throw new NetworkException('Always fails', 0, null, $request);
            }
        );
    }

    public function test_returns_last_5xx_when_all_attempts_exhausted(): void
    {
        $interceptor = new RetryInterceptor(maxAttempts: 2, baseDelaySeconds: 0.0);

        $response = $interceptor->process(
            $this->makeRequest(),
            fn(Request $r) => $this->makeResponse(500)
        );

        $this->assertSame(500, $response->getStatusCode());
    }

    // ─── No Retry on 4xx ─────────────────────────────────────────────────────

    public function test_does_not_retry_on_404(): void
    {
        $interceptor = new RetryInterceptor(maxAttempts: 3, baseDelaySeconds: 0.0);
        $attempts    = 0;

        $response = $interceptor->process(
            $this->makeRequest(),
            function (Request $r) use (&$attempts): Response {
                $attempts++;

                return $this->makeResponse(404);
            }
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(1, $attempts);
    }

    public function test_does_not_retry_on_401(): void
    {
        $interceptor = new RetryInterceptor(maxAttempts: 3, baseDelaySeconds: 0.0);
        $attempts    = 0;

        $interceptor->process(
            $this->makeRequest(),
            function (Request $r) use (&$attempts): Response {
                $attempts++;

                return $this->makeResponse(401);
            }
        );

        $this->assertSame(1, $attempts);
    }

    // ─── Retry-After Header ────────────────────────────────────────────────────

    public function test_respects_retry_after_header_in_delay_calculation(): void
    {
        // We can't easily test the actual sleep, but we can test that a
        // Retry-After of a very large number would be capped at maxDelaySeconds.
        // The real test is that we don't throw a negative sleep error.
        $interceptor = new RetryInterceptor(
            maxAttempts: 2,
            baseDelaySeconds: 0.0,
            maxDelaySeconds: 0.001, // 1ms cap
        );

        $attempts = 0;

        $response = $interceptor->process(
            $this->makeRequest(),
            function (Request $r) use (&$attempts): Response {
                $attempts++;

                if ($attempts === 1) {
                    return new Response(429, [
                        'Retry-After' => ['100'], // Server says wait 100s
                    ], Stream::fromString(''));
                }

                return $this->makeResponse(200);
            }
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(2, $attempts);
    }

    // ─── Custom shouldRetry Callback ───────────────────────────────────────────

    public function test_custom_should_retry_callback_respected(): void
    {
        $interceptor = new RetryInterceptor(
            maxAttempts: 3,
            baseDelaySeconds: 0.0,
            shouldRetry: static function (Request $r, ?Response $response, ?\Throwable $e): bool {
                // Only retry on 422, not 500
                return $response !== null && $response->getStatusCode() === 422;
            }
        );

        $attempts = 0;

        // 422 should be retried
        $interceptor->process(
            $this->makeRequest(),
            function (Request $r) use (&$attempts): Response {
                $attempts++;

                return $attempts < 3
                    ? $this->makeResponse(422)
                    : $this->makeResponse(200);
            }
        );

        $this->assertSame(3, $attempts);
    }

    // ─── onRetry Callback ─────────────────────────────────────────────────────

    public function test_on_retry_callback_is_fired_on_each_retry(): void
    {
        $retryEvents = [];

        $interceptor = new RetryInterceptor(
            maxAttempts: 3,
            baseDelaySeconds: 0.0,
            onRetry: function (Request $r, ?Response $resp, ?\Throwable $e, int $attempt, float $delay) use (&$retryEvents): void {
                $retryEvents[] = ['attempt' => $attempt, 'status' => $resp?->getStatusCode()];
            }
        );

        $interceptor->process(
            $this->makeRequest(),
            function (Request $r) use (&$attempts): Response {
                static $count = 0;
                $count++;

                return $count < 3 ? $this->makeResponse(500) : $this->makeResponse(200);
            }
        );

        $this->assertCount(2, $retryEvents);
        $this->assertSame(0, $retryEvents[0]['attempt']);
        $this->assertSame(1, $retryEvents[1]['attempt']);
        $this->assertSame(500, $retryEvents[0]['status']);
    }
}
