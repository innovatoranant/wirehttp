<?php

declare(strict_types=1);

namespace WireHttp\Exceptions;

use WireHttp\Http\Request;

/**
 * NetworkException — Transport-Level Failure
 *
 * Thrown when the HTTP request could not be sent because of a network-level error,
 * BEFORE any HTTP response was received from the server.
 *
 * Examples of errors that throw NetworkException:
 *  - DNS resolution failure (host not found)
 *  - TCP connection refused
 *  - TLS/SSL handshake failure (invalid certificate, etc.)
 *  - Unexpected connection drop after sending request headers
 *  - cURL errors at the transport layer (CURLE_COULDNT_CONNECT, CURLE_SSL_CERTPROBLEM, etc.)
 *
 * Note: TimeoutException extends this class (a timeout is a network-level failure).
 * Note: This class deliberately has no `$response` property — network failures
 *       produce no HTTP response, so having a nullable response would be misleading.
 *
 * PSR-18 Compliance:
 *  This maps to PSR-18's `Psr\Http\Client\NetworkExceptionInterface`. While we do not
 *  import that PSR package (zero-dependency policy), our interface is structurally
 *  compatible with it.
 */
class NetworkException extends WireHttpException
{
    /**
     * The cURL error number (CURLE_*), if applicable.
     * 0 if the error did not originate from cURL.
     */
    private readonly int $curlErrorNo;

    /**
     * The raw cURL error string, if applicable.
     */
    private readonly string $curlErrorString;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?Request $request = null,
        int $curlErrorNo = 0,
        string $curlErrorString = '',
        array $context = [],
    ) {
        parent::__construct(
            message: $message,
            code: $code,
            previous: $previous,
            request: $request,
            response: null, // Network exceptions never have a response
            context: array_merge($context, [
                'curl_errno'  => $curlErrorNo,
                'curl_error'  => $curlErrorString,
            ]),
        );

        $this->curlErrorNo     = $curlErrorNo;
        $this->curlErrorString = $curlErrorString;
    }

    /**
     * Returns the cURL error number (CURLE_* constant value) if this error originated
     * from cURL. Returns 0 if the error was generated at a higher level.
     */
    public function getCurlErrorNo(): int
    {
        return $this->curlErrorNo;
    }

    /**
     * Returns the human-readable cURL error string associated with the cURL error number.
     * Returns an empty string if not applicable.
     */
    public function getCurlErrorString(): string
    {
        return $this->curlErrorString;
    }

    /**
     * Returns true if this exception originated from a cURL transport failure.
     */
    public function isCurlError(): bool
    {
        return $this->curlErrorNo > 0;
    }

    /**
     * Returns true if this exception is likely related to DNS resolution.
     * CURLE_COULDNT_RESOLVE_HOST = 6
     * CURLE_COULDNT_RESOLVE_PROXY = 5
     */
    public function isDnsError(): bool
    {
        return in_array($this->curlErrorNo, [5, 6], strict: true);
    }

    /**
     * Returns true if this exception is related to a connection failure.
     * CURLE_COULDNT_CONNECT = 7
     * CURLE_INTERFACE_FAILED = 45
     */
    public function isConnectionError(): bool
    {
        return in_array($this->curlErrorNo, [7, 45], strict: true);
    }

    /**
     * Returns true if this exception is related to an SSL/TLS failure.
     * Covers CURLE_SSL_CONNECT_ERROR (35), CURLE_SSL_CERTPROBLEM (58),
     * CURLE_SSL_CIPHER (59), CURLE_PEER_FAILED_VERIFICATION (60),
     * CURLE_SSL_ENGINE_NOTFOUND (53), CURLE_SSL_ENGINE_SETFAILED (54), etc.
     */
    public function isSslError(): bool
    {
        return in_array($this->curlErrorNo, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 83, 90, 91], strict: true);
    }

    /**
     * Factory method: create a NetworkException from a cURL handle after failure.
     *
     * @param \CurlHandle $curlHandle The cURL handle where the error occurred.
     * @param Request|null $request   The request that was being sent.
     */
    public static function fromCurlHandle(\CurlHandle $curlHandle, ?Request $request = null): static
    {
        $errno  = curl_errno($curlHandle);
        $errmsg = curl_error($curlHandle);

        return new static(
            message: sprintf(
                'cURL error %d: %s (see https://curl.se/libcurl/c/libcurl-errors.html)',
                $errno,
                $errmsg ?: 'Unknown cURL error'
            ),
            code: $errno,
            request: $request,
            curlErrorNo: $errno,
            curlErrorString: $errmsg,
        );
    }
}
