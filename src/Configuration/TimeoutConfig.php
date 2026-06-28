<?php

declare(strict_types=1);

namespace WireHttp\Configuration;

/**
 * TimeoutConfig — Granular Timeout Settings DTO
 *
 * Controls every timeout dimension independently. Collapsed into a single
 * float "timeout" in simpler clients — WireHTTP exposes the full control.
 *
 * Relationship between connect and request timeouts:
 * ---------------------------------------------------
 * `connectSeconds` is only the TCP handshake + TLS negotiation phase.
 * `requestSeconds` is the TOTAL time for the entire request cycle,
 * including connection, SSL, sending headers+body, and receiving the response.
 *
 * A well-configured client typically sets:
 *   connectSeconds = 5.0   (fail fast on unreachable hosts)
 *   requestSeconds = 30.0  (allow 30s for the full response)
 */
final class TimeoutConfig
{
    public function __construct(
        /** Time to establish TCP connection + complete TLS handshake, in seconds. */
        public readonly float $connectSeconds = 10.0,

        /** Total request lifecycle timeout, in seconds (0 = unlimited — NOT recommended). */
        public readonly float $requestSeconds = 30.0,
    ) {
        if ($connectSeconds < 0) {
            throw new \InvalidArgumentException('connectSeconds must be >= 0.');
        }

        if ($requestSeconds < 0) {
            throw new \InvalidArgumentException('requestSeconds must be >= 0.');
        }
    }

    /**
     * Creates a TimeoutConfig with a single value applied to both connect and request.
     */
    public static function of(float $seconds): static
    {
        return new static(connectSeconds: $seconds, requestSeconds: $seconds);
    }

    /**
     * Creates a TimeoutConfig suitable for fast, low-latency internal APIs.
     * Connect: 2s, Total: 10s
     */
    public static function fast(): static
    {
        return new static(connectSeconds: 2.0, requestSeconds: 10.0);
    }

    /**
     * Creates a TimeoutConfig suitable for slow external APIs or file downloads.
     * Connect: 5s, Total: 120s
     */
    public static function slow(): static
    {
        return new static(connectSeconds: 5.0, requestSeconds: 120.0);
    }

    /**
     * Disables all timeouts. Use ONLY in testing or long-running CLI scripts.
     */
    public static function unlimited(): static
    {
        return new static(connectSeconds: 0.0, requestSeconds: 0.0);
    }
}
