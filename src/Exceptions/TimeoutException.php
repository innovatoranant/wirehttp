<?php

declare(strict_types=1);

namespace WireHttp\Exceptions;

use WireHttp\Http\Request;

/**
 * TimeoutException — Request or Connection Timeout
 *
 * Thrown when a request exceeds the configured timeout threshold.
 * This is a specialized subtype of NetworkException because a timeout is
 * a network-level failure — no HTTP response is ever received.
 *
 * WireHTTP distinguishes between two types of timeouts:
 *
 *  1. **Connect Timeout**: The time limit for establishing the TCP (or QUIC) connection
 *     to the server. If the server does not accept the connection within this window,
 *     a TimeoutException with `isConnectTimeout() === true` is thrown.
 *     Maps to cURL option: CURLOPT_CONNECTTIMEOUT_MS
 *     cURL errno: CURLE_OPERATION_TIMEDOUT (28) or CURLE_COULDNT_CONNECT (7)
 *
 *  2. **Request Timeout**: The total time limit for the entire request/response cycle,
 *     from the moment the connection is established until the last byte of the response
 *     body is received. Maps to cURL option: CURLOPT_TIMEOUT_MS
 *     cURL errno: CURLE_OPERATION_TIMEDOUT (28)
 *
 * Usage:
 *   try {
 *       Wire::get('https://slow.api.com')->timeout(seconds: 3)->send();
 *   } catch (TimeoutException $e) {
 *       if ($e->isConnectTimeout()) {
 *           // Could not even reach the server
 *       } else {
 *           // Server was reached but took too long to respond
 *           echo "Timed out after {$e->getConfiguredTimeout()}s";
 *       }
 *   }
 */
class TimeoutException extends NetworkException
{
    /**
     * Whether the timeout occurred during the TCP connection phase (true)
     * or during the request/response data transfer phase (false).
     */
    private readonly bool $connectTimeout;

    /**
     * The timeout value (in seconds, as a float) that was configured when the
     * request failed. This allows the consumer to log exactly which threshold was exceeded.
     */
    private readonly float $configuredTimeoutSeconds;

    /**
     * The actual elapsed time in seconds before the timeout was triggered.
     * This may be slightly higher than $configuredTimeoutSeconds due to OS scheduling.
     */
    private readonly float $elapsedSeconds;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?Request $request = null,
        bool $connectTimeout = false,
        float $configuredTimeoutSeconds = 0.0,
        float $elapsedSeconds = 0.0,
        int $curlErrorNo = 28, // CURLE_OPERATION_TIMEDOUT
        string $curlErrorString = 'Operation timed out',
        array $context = [],
    ) {
        $this->connectTimeout           = $connectTimeout;
        $this->configuredTimeoutSeconds = $configuredTimeoutSeconds;
        $this->elapsedSeconds           = $elapsedSeconds;

        parent::__construct(
            message: $message ?: static::buildMessage($connectTimeout, $configuredTimeoutSeconds),
            code: $code,
            previous: $previous,
            request: $request,
            curlErrorNo: $curlErrorNo,
            curlErrorString: $curlErrorString,
            context: array_merge($context, [
                'type'                => $connectTimeout ? 'connect_timeout' : 'request_timeout',
                'configured_timeout'  => $configuredTimeoutSeconds,
                'elapsed_seconds'     => $elapsedSeconds,
            ]),
        );
    }

    /**
     * Returns true if the timeout occurred during TCP connection establishment.
     * Returns false if the timeout occurred after connection, during data transfer.
     */
    public function isConnectTimeout(): bool
    {
        return $this->connectTimeout;
    }

    /**
     * Returns true if the timeout occurred during the request/response data transfer phase.
     */
    public function isRequestTimeout(): bool
    {
        return !$this->connectTimeout;
    }

    /**
     * Returns the timeout threshold (in seconds) that was configured for this request.
     * Useful for logging: "Request timed out after 5.0s (configured: 5.0s)"
     */
    public function getConfiguredTimeout(): float
    {
        return $this->configuredTimeoutSeconds;
    }

    /**
     * Returns the actual elapsed time in seconds before the timeout was triggered.
     */
    public function getElapsedSeconds(): float
    {
        return $this->elapsedSeconds;
    }

    /**
     * Returns a formatted string representation of how much of the timeout was consumed.
     * Example: "3.42s / 5.00s"
     */
    public function getTimeoutRatio(): string
    {
        return sprintf(
            '%.2fs / %.2fs',
            $this->elapsedSeconds,
            $this->configuredTimeoutSeconds
        );
    }

    /**
     * Factory method: create a connect timeout from a cURL handle.
     */
    public static function fromConnectTimeout(
        \CurlHandle $curlHandle,
        float $configuredTimeoutSeconds,
        ?Request $request = null,
    ): static {
        $errno  = curl_errno($curlHandle);
        $errmsg = curl_error($curlHandle);

        $info    = curl_getinfo($curlHandle);
        $elapsed = (float) ($info['total_time'] ?? 0.0);

        return new static(
            message: sprintf(
                'Connection timed out after %.2fs (configured: %.2fs). %s',
                $elapsed,
                $configuredTimeoutSeconds,
                $errmsg
            ),
            code: $errno,
            request: $request,
            connectTimeout: true,
            configuredTimeoutSeconds: $configuredTimeoutSeconds,
            elapsedSeconds: $elapsed,
            curlErrorNo: $errno,
            curlErrorString: $errmsg,
        );
    }

    /**
     * Factory method: create a request timeout from a cURL handle.
     */
    public static function fromRequestTimeout(
        \CurlHandle $curlHandle,
        float $configuredTimeoutSeconds,
        ?Request $request = null,
    ): static {
        $errno  = curl_errno($curlHandle);
        $errmsg = curl_error($curlHandle);

        $info    = curl_getinfo($curlHandle);
        $elapsed = (float) ($info['total_time'] ?? 0.0);

        return new static(
            message: sprintf(
                'Request timed out after %.2fs (configured: %.2fs). %s',
                $elapsed,
                $configuredTimeoutSeconds,
                $errmsg
            ),
            code: $errno,
            request: $request,
            connectTimeout: false,
            configuredTimeoutSeconds: $configuredTimeoutSeconds,
            elapsedSeconds: $elapsed,
            curlErrorNo: $errno,
            curlErrorString: $errmsg,
        );
    }

    private static function buildMessage(bool $connectTimeout, float $timeout): string
    {
        return $connectTimeout
            ? sprintf('Connection timed out (configured: %.2fs)', $timeout)
            : sprintf('Request timed out (configured: %.2fs)', $timeout);
    }
}
