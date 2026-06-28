<?php

declare(strict_types=1);

namespace WireHttp\Enums;

/**
 * HTTP Version Enum
 *
 * Represents the HTTP protocol version to be used for a given request.
 * WireHTTP supports HTTP/1.0, HTTP/1.1, HTTP/2 and HTTP/3 (QUIC) out of the box.
 * The version is mapped to the exact string value used in the HTTP wire format
 * and in cURL option values.
 */
enum HttpVersion: string
{
    /**
     * HTTP/1.0 — The original simple protocol.
     * Each request uses a separate TCP connection. No persistent connections.
     * Use only for legacy server compatibility.
     */
    case HTTP_1_0 = '1.0';

    /**
     * HTTP/1.1 — The widely deployed standard. (RFC 7230–7235)
     * Supports persistent connections (keep-alive), chunked transfer encoding,
     * virtual hosting, and pipelining (rarely used in practice).
     * This is WireHTTP's default fallback version.
     */
    case HTTP_1_1 = '1.1';

    /**
     * HTTP/2 — The modern, high-performance protocol. (RFC 7540)
     * Binary framing layer, header compression (HPACK), stream multiplexing,
     * server push, and flow control. Requires TLS in virtually all real-world
     * deployments (h2 over TLS). WireHTTP will negotiate this via ALPN.
     */
    case HTTP_2 = '2';

    /**
     * HTTP/3 — The bleeding-edge protocol built on QUIC. (RFC 9114)
     * Uses UDP instead of TCP for transport, eliminating head-of-line blocking
     * entirely. Requires a cURL build compiled with the ngtcp2 or quiche library.
     * WireHTTP will enable this when the cURL CURLOPT_HTTP_VERSION constant
     * CURL_HTTP_VERSION_3 is available.
     */
    case HTTP_3 = '3';

    /**
     * Returns the corresponding cURL CURLOPT_HTTP_VERSION constant value.
     *
     * These are integer constants used internally by cURL to specify protocol version.
     * We map them here to avoid magic integer constants scattered across the codebase.
     *
     * @return int The cURL constant value for this HTTP version.
     */
    public function toCurlVersion(): int
    {
        return match ($this) {
            self::HTTP_1_0 => CURL_HTTP_VERSION_1_0,
            self::HTTP_1_1 => CURL_HTTP_VERSION_1_1,
            self::HTTP_2   => CURL_HTTP_VERSION_2_0,
            self::HTTP_3   => defined('CURL_HTTP_VERSION_3')
                ? CURL_HTTP_VERSION_3
                : throw new \RuntimeException(
                    'HTTP/3 is not supported by the installed cURL version. ' .
                    'Please upgrade to a cURL build with QUIC support (ngtcp2 or quiche).'
                ),
        };
    }

    /**
     * Returns the human-readable HTTP version string used in the protocol wire format.
     * For example: "HTTP/1.1", "HTTP/2", "HTTP/3".
     */
    public function toProtocolString(): string
    {
        return 'HTTP/' . $this->value;
    }

    /**
     * Returns true if this version supports multiplexed streams over a single connection.
     * Only HTTP/2 and HTTP/3 support native multiplexing.
     */
    public function supportsMultiplexing(): bool
    {
        return match ($this) {
            self::HTTP_2, self::HTTP_3 => true,
            default => false,
        };
    }

    /**
     * Returns true if this version uses binary framing rather than plaintext.
     * Binary framing is more efficient for parsing, used in HTTP/2 and HTTP/3.
     */
    public function isBinaryProtocol(): bool
    {
        return match ($this) {
            self::HTTP_2, self::HTTP_3 => true,
            default => false,
        };
    }

    /**
     * Returns true if this protocol runs over QUIC/UDP rather than TCP.
     */
    public function isQUIC(): bool
    {
        return $this === self::HTTP_3;
    }

    /**
     * Returns the default (recommended) HTTP version for new WireHTTP clients.
     * We default to HTTP/1.1 for maximum compatibility, with HTTP/2 negotiated
     * automatically via ALPN when the server supports it.
     */
    public static function default(): self
    {
        return self::HTTP_1_1;
    }

    /**
     * Attempts to construct an HttpVersion from a raw version string.
     * Accepts common formats: "1.1", "HTTP/1.1", "h2", "h3", "2.0", "2", "3"
     *
     * @throws \ValueError if the string cannot be mapped to a known version.
     */
    public static function fromString(string $version): self
    {
        $normalized = match (strtolower(trim($version))) {
            '1.0', 'http/1.0'                  => '1.0',
            '1.1', 'http/1.1'                  => '1.1',
            '2',   'http/2', 'h2', '2.0'       => '2',
            '3',   'http/3', 'h3', '3.0'       => '3',
            default => throw new \ValueError(
                sprintf('"%s" is not a valid or supported HTTP version string.', $version)
            ),
        };

        return self::from($normalized);
    }
}
