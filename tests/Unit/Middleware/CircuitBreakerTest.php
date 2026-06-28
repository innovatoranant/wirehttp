<?php

declare(strict_types=1);

namespace WireHttp\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use WireHttp\Http\Request;
use WireHttp\Http\Response;
use WireHttp\Http\Stream;
use WireHttp\Middleware\Core\CircuitBreakerInterceptor;

final class CircuitBreakerTest extends TestCase
{
    private const DOMAIN_KEY = 'https://api.example.com:443';

    private function makeRequest(): Request
    {
        return new Request('GET', 'https://api.example.com/data');
    }

    private function makeResponse(int $status): Response
    {
        return new Response($status, [], Stream::fromString(''));
    }

    private function makeBreaker(
        int   $failureThreshold = 3,
        float $windowSeconds = 60.0,
        float $cooldownSeconds = 30.0,
        int   $successThreshold = 1,
    ): CircuitBreakerInterceptor {
        return new CircuitBreakerInterceptor(
            failureThreshold: $failureThreshold,
            windowSeconds: $windowSeconds,
            cooldownSeconds: $cooldownSeconds,
            successThreshold: $successThreshold,
        );
    }

    // ─── CLOSED State (Normal) ────────────────────────────────────────────────

    public function test_starts_in_closed_state(): void
    {
        $breaker = $this->makeBreaker();

        // Should NOT be open — requests pass through
        $called = false;

        $breaker->process(
            $this->makeRequest(),
            function (Request $r) use (&$called): Response {
                $called = true;

                return $this->makeResponse(200);
            }
        );

        $this->assertTrue($called);
        $this->assertSame('closed', $breaker->getState(self::DOMAIN_KEY));
    }

    public function test_remains_closed_below_failure_threshold(): void
    {
        $breaker = $this->makeBreaker(failureThreshold: 5);

        for ($i = 0; $i < 4; $i++) {
            $breaker->process(
                $this->makeRequest(),
                fn(Request $r) => $this->makeResponse(500)
            );
        }

        // Still closed — only 4 failures, threshold is 5
        $this->assertSame('closed', $breaker->getState(self::DOMAIN_KEY));
    }

    // ─── OPEN State ───────────────────────────────────────────────────────────

    public function test_opens_after_failure_threshold(): void
    {
        $breaker = $this->makeBreaker(failureThreshold: 3);

        for ($i = 0; $i < 3; $i++) {
            $breaker->process(
                $this->makeRequest(),
                fn(Request $r) => $this->makeResponse(500)
            );
        }

        $this->assertSame('open', $breaker->getState(self::DOMAIN_KEY));
    }

    public function test_open_circuit_rejects_requests_immediately(): void
    {
        $breaker = $this->makeBreaker(failureThreshold: 2, cooldownSeconds: 9999.0);

        // Trip the circuit
        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->process(
                    $this->makeRequest(),
                    fn(Request $r) => $this->makeResponse(500)
                );
            } catch (\Throwable) {
            }
        }

        $this->assertSame('open', $breaker->getState(self::DOMAIN_KEY));

        // Now verify requests are rejected
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Circuit breaker OPEN/');

        $breaker->process(
            $this->makeRequest(),
            fn(Request $r) => $this->makeResponse(200)
        );
    }

    public function test_open_circuit_does_not_call_next(): void
    {
        $breaker = $this->makeBreaker(failureThreshold: 1, cooldownSeconds: 9999.0);

        // Trip the circuit
        try {
            $breaker->process($this->makeRequest(), fn(Request $r) => $this->makeResponse(500));
        } catch (\Throwable) {
        }

        $nextCalled = false;

        try {
            $breaker->process(
                $this->makeRequest(),
                function (Request $r) use (&$nextCalled): Response {
                    $nextCalled = true;

                    return $this->makeResponse(200);
                }
            );
        } catch (\RuntimeException) {
        }

        $this->assertFalse($nextCalled);
    }

    // ─── HALF-OPEN State ──────────────────────────────────────────────────────

    public function test_transitions_to_half_open_after_cooldown(): void
    {
        // Use a tiny cooldown that has definitely expired
        $breaker = $this->makeBreaker(failureThreshold: 1, cooldownSeconds: 0.001);

        // Trip the circuit
        $breaker->process($this->makeRequest(), fn(Request $r) => $this->makeResponse(500));

        $this->assertSame('open', $breaker->getState(self::DOMAIN_KEY));

        // Wait for cooldown to expire
        usleep(5_000); // 5ms > 1ms cooldown

        // Next request should probe (succeed → close)
        $breaker->process(
            $this->makeRequest(),
            fn(Request $r) => $this->makeResponse(200)
        );

        $this->assertSame('closed', $breaker->getState(self::DOMAIN_KEY));
    }

    public function test_probe_failure_reopens_circuit(): void
    {
        $breaker = $this->makeBreaker(failureThreshold: 1, cooldownSeconds: 0.001);

        // Trip the circuit
        $breaker->process($this->makeRequest(), fn(Request $r) => $this->makeResponse(500));

        usleep(5_000); // Wait for cooldown

        // Probe fails
        $breaker->process($this->makeRequest(), fn(Request $r) => $this->makeResponse(500));

        $this->assertSame('open', $breaker->getState(self::DOMAIN_KEY));
    }

    // ─── Exception-Based Failures ─────────────────────────────────────────────

    public function test_counts_exceptions_as_failures(): void
    {
        $breaker = $this->makeBreaker(failureThreshold: 2);
        $request = $this->makeRequest();

        for ($i = 0; $i < 2; $i++) {
            try {
                $breaker->process(
                    $request,
                    function () use ($request): never {
                        throw new \WireHttp\Exceptions\NetworkException('Refused', $request);
                    }
                );
            } catch (\Throwable) {
            }
        }

        $this->assertSame('open', $breaker->getState(self::DOMAIN_KEY));
    }

    // ─── State Change Callback ────────────────────────────────────────────────

    public function test_fires_state_change_callback_on_open(): void
    {
        $transitions = [];

        $breaker = new CircuitBreakerInterceptor(
            failureThreshold: 1,
            onStateChange: function (string $domain, string $old, string $new) use (&$transitions): void {
                $transitions[] = "{$old}→{$new}";
            }
        );

        $breaker->process($this->makeRequest(), fn(Request $r) => $this->makeResponse(500));

        $this->assertContains('closed→open', $transitions);
    }

    // ─── Admin Controls ───────────────────────────────────────────────────────

    public function test_force_close_resets_open_circuit(): void
    {
        $breaker = $this->makeBreaker(failureThreshold: 1, cooldownSeconds: 9999.0);

        // Trip the circuit
        $breaker->process($this->makeRequest(), fn(Request $r) => $this->makeResponse(500));
        $this->assertSame('open', $breaker->getState(self::DOMAIN_KEY));

        // Admin force-close
        $breaker->forceClose(self::DOMAIN_KEY);
        $this->assertSame('closed', $breaker->getState(self::DOMAIN_KEY));

        // Requests flow through again
        $called = false;
        $breaker->process(
            $this->makeRequest(),
            function (Request $r) use (&$called): Response {
                $called = true;

                return $this->makeResponse(200);
            }
        );

        $this->assertTrue($called);
    }

    // ─── getAllStates Introspection ────────────────────────────────────────────

    public function test_get_all_states_returns_snapshot(): void
    {
        $breaker = $this->makeBreaker(failureThreshold: 1);

        $breaker->process($this->makeRequest(), fn(Request $r) => $this->makeResponse(500));

        $states = $breaker->getAllStates();

        $this->assertArrayHasKey(self::DOMAIN_KEY, $states);
        $this->assertSame('open', $states[self::DOMAIN_KEY]['state']);
    }
}
