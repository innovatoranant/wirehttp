<?php

declare(strict_types=1);

namespace WireHttp\Exceptions;

use WireHttp\Http\Request;
use WireHttp\Http\Response;

/**
 * WireHttpException — Base Exception for the Entire WireHTTP Library
 *
 * All exceptions thrown by WireHTTP extend this class. This allows consumers of
 * the library to write a single catch block to handle any WireHTTP failure:
 *
 *   try {
 *       $response = Wire::get('https://api.example.com/users')->send();
 *   } catch (WireHttpException $e) {
 *       // Handle any WireHTTP-related error
 *       $request = $e->getRequest();   // Always available
 *       $response = $e->getResponse(); // Null if the error was before a response arrived
 *   }
 *
 * The exception hierarchy is:
 *
 *  WireHttpException (Base)
 *  ├── NetworkException       (DNS/connection/TLS failure — NO response)
 *  │   └── TimeoutException   (Request or connect timeout)
 *  ├── RequestException       (HTTP-level errors — response IS available)
 *  │   ├── ClientException    (4xx errors)
 *  │   └── ServerException    (5xx errors)
 *  └── TooManyRedirectsException
 */
class WireHttpException extends \RuntimeException
{
    /**
     * The PSR-7-compatible Request that triggered this exception.
     * Always available so the developer can inspect what was sent.
     */
    protected readonly ?Request $request;

    /**
     * The PSR-7-compatible Response that was received, if any.
     * This is null for network-level failures (DNS, timeout, TLS) where
     * no HTTP response was ever received from the server.
     */
    protected readonly ?Response $response;

    /**
     * Additional structured context about the failure.
     * Useful for logging and debugging without having to parse messages.
     * e.g., ['url' => '...', 'method' => 'GET', 'duration_ms' => 1500]
     */
    protected readonly array $context;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?Request $request = null,
        ?Response $response = null,
        array $context = [],
    ) {
        parent::__construct($message, $code, $previous);

        $this->request  = $request;
        $this->response = $response;
        $this->context  = $context;
    }

    /**
     * Returns the request that triggered this exception.
     * Returns null if the exception was thrown before a request was fully constructed.
     */
    public function getRequest(): ?Request
    {
        return $this->request;
    }

    /**
     * Returns the HTTP response if one was received from the server.
     * Returns null if the failure occurred at the network level (no response received).
     */
    public function getResponse(): ?Response
    {
        return $this->response;
    }

    /**
     * Returns structured context data associated with this failure.
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Returns true if a response was received from the server.
     * This is useful to distinguish between network failures and HTTP errors.
     */
    public function hasResponse(): bool
    {
        return $this->response !== null;
    }

    /**
     * Returns a developer-friendly multi-line string representation of the exception
     * including the request details, response details (if any), and the context.
     */
    public function toDebugString(): string
    {
        $lines = [
            sprintf('[WireHTTP] %s (code: %d)', $this->getMessage(), $this->getCode()),
            sprintf('  Exception: %s', static::class),
        ];

        if ($this->request !== null) {
            $lines[] = sprintf(
                '  Request:   %s %s',
                $this->request->getMethod(),
                (string) $this->request->getUri()
            );
        }

        if ($this->response !== null) {
            $lines[] = sprintf(
                '  Response:  HTTP %d %s',
                $this->response->getStatusCode(),
                $this->response->getReasonPhrase()
            );
        }

        if (!empty($this->context)) {
            $lines[] = '  Context:';
            foreach ($this->context as $key => $value) {
                $lines[] = sprintf('    %s: %s', $key, is_scalar($value) ? $value : json_encode($value));
            }
        }

        if ($this->getPrevious() !== null) {
            $lines[] = sprintf('  Caused by: %s: %s', get_class($this->getPrevious()), $this->getPrevious()->getMessage());
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Creates a WireHttpException from an existing throwable, wrapping it.
     * Useful when catching unexpected exceptions inside the transport layer.
     */
    public static function wrap(\Throwable $e, ?Request $request = null): static
    {
        return new static(
            message: $e->getMessage(),
            code: $e->getCode(),
            previous: $e,
            request: $request,
        );
    }
}
