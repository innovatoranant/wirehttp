<?php

declare(strict_types=1);

namespace WireHttp\Http\Factory;

use WireHttp\Http\Response;
use WireHttp\Http\Stream;

/**
 * ResponseFactory — Creates Response instances.
 *
 * This is WireHTTP's implementation of PSR-17's ResponseFactoryInterface.
 * Used primarily by the Transport layer when it constructs Response objects
 * from raw cURL output, and by MockTransport when faking responses in tests.
 */
final class ResponseFactory
{
    /**
     * Creates a new Response with the given status code.
     *
     * @param int    $code         The HTTP status code (100–599).
     * @param string $reasonPhrase Optional override for the reason phrase.
     *                             If empty, derived automatically from the status code.
     */
    public function createResponse(int $code = 200, string $reasonPhrase = ''): Response
    {
        return new Response($code, reasonPhrase: $reasonPhrase);
    }

    /**
     * Creates a 200 OK Response with an optional body string.
     */
    public function ok(string $body = '', string $contentType = 'text/plain'): Response
    {
        $stream = Stream::fromString($body);

        return new Response(200, [
            'Content-Type'   => $contentType,
            'Content-Length' => (string) strlen($body),
        ], $stream);
    }

    /**
     * Creates a 200 OK Response with a JSON body.
     *
     * @param array<mixed>|object $data The data to encode as JSON.
     */
    public function json(mixed $data, int $status = 200): Response
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $stream  = Stream::fromString($encoded);

        return new Response($status, [
            'Content-Type'   => 'application/json; charset=utf-8',
            'Content-Length' => (string) strlen($encoded),
        ], $stream);
    }

    /**
     * Creates a redirect Response (301, 302, 307, or 308).
     *
     * @param string $location The URL to redirect to.
     * @param int    $status   The redirect status code (default: 302 Found).
     */
    public function redirect(string $location, int $status = 302): Response
    {
        return new Response($status, ['Location' => $location]);
    }

    /**
     * Creates a 204 No Content Response.
     */
    public function noContent(): Response
    {
        return new Response(204);
    }

    /**
     * Creates a Response from raw HTTP response bytes (used by the transport layer).
     * Parses the status line, headers, and body from a complete HTTP response string.
     *
     * @param string $rawResponse The full raw HTTP response (headers + body).
     * @throws \InvalidArgumentException if the raw response cannot be parsed.
     */
    public function fromRawResponse(string $rawResponse): Response
    {
        // Find the boundary between headers and body
        $headerEndPos = strpos($rawResponse, "\r\n\r\n");

        if ($headerEndPos === false) {
            // Try LF-only in case of non-standard server
            $headerEndPos = strpos($rawResponse, "\n\n");
            $separator    = "\n";
        } else {
            $separator = "\r\n";
        }

        if ($headerEndPos === false) {
            throw new \InvalidArgumentException('Cannot parse raw HTTP response: missing header/body separator.');
        }

        $headerSection = substr($rawResponse, 0, $headerEndPos);
        $bodyContent   = substr($rawResponse, $headerEndPos + strlen($separator . $separator));

        $lines     = explode($separator, $headerSection);
        $statusLine = array_shift($lines);

        // Parse: HTTP/1.1 200 OK
        if (!preg_match('/HTTP\/(\d+(?:\.\d+)?)\s+(\d{3})(?:\s+(.+))?/', $statusLine, $matches)) {
            throw new \InvalidArgumentException(
                sprintf('Cannot parse HTTP status line: "%s"', $statusLine)
            );
        }

        $version      = $matches[1];
        $statusCode   = (int) $matches[2];
        $reasonPhrase = trim($matches[3] ?? '');

        // Parse headers
        $headers = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $colonPos = strpos($line, ':');

            if ($colonPos === false) {
                continue;
            }

            $headerName  = trim(substr($line, 0, $colonPos));
            $headerValue = trim(substr($line, $colonPos + 1));

            // Handle multiple values for the same header
            if (isset($headers[$headerName])) {
                $headers[$headerName][] = $headerValue;
            } else {
                $headers[$headerName] = [$headerValue];
            }
        }

        $stream = Stream::fromString($bodyContent);

        return new Response($statusCode, $headers, $stream, $version, $reasonPhrase);
    }
}
