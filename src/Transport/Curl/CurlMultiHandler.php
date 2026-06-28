<?php

declare(strict_types=1);

namespace WireHttp\Transport\Curl;

use WireHttp\Async\Deferred;
use WireHttp\Async\Future;
use WireHttp\Async\Loop;
use WireHttp\Exceptions\NetworkException;
use WireHttp\Http\Request;
use WireHttp\Http\Response;
use WireHttp\Http\Stream;
use WireHttp\Transport\TransportInterface;

/**
 * CurlMultiHandler — Fiber-Driven Concurrent HTTP Transport
 *
 * This is the crown jewel of WireHTTP's transport layer. It integrates PHP 8.1
 * Fibers with `curl_multi_*` functions to enable truly concurrent, non-blocking
 * HTTP requests without any event loop dependency beyond WireHTTP itself.
 *
 * How it Works (end-to-end flow):
 * --------------------------------
 * 1. Developer calls `Wire::get('/api/users')->sendAsync()`.
 * 2. `CurlMultiHandler::sendAsync()` is called.
 * 3. We create a `Deferred` and a response buffer (headers string + body Stream).
 * 4. We call `CurlFactory::create()` to get a fully-configured `\CurlHandle`.
 * 5. We pass the handle and Deferred to `Loop::addHandle()`.
 * 6. The Loop adds it to `curl_multi` and starts a Fiber that immediately suspends.
 * 7. We return `$deferred->getFuture()` to the developer — INSTANTLY (non-blocking).
 * 8. Developer calls `$future->get()` from within a Fiber → their Fiber suspends.
 * 9. The Loop continues ticking via `curl_multi_exec()`.
 * 10. When the server responds, `curl_multi_info_read()` returns the completed handle.
 * 11. The Loop calls `FiberManager::resolve()` → `Deferred::resolve()` → `Future::resolve()`.
 * 12. `Future::resolve()` resumes the developer's Fiber with the Response.
 * 13. `$future->get()` returns the Response object. Done.
 *
 * Concurrency in Practice:
 * -------------------------
 * All of the following requests are sent SIMULTANEOUSLY and complete in parallel:
 *
 *   $users  = Wire::get('/api/users')->sendAsync();
 *   $posts  = Wire::get('/api/posts')->sendAsync();
 *   $config = Wire::get('/api/config')->sendAsync();
 *
 *   [$usersResp, $postsResp, $configResp] = Future::all($users, $posts, $config);
 *
 * If each request takes 200ms, the total time is ~200ms (not 600ms).
 *
 * For `send()` (synchronous), we wrap `sendAsync()` and call `$future->get()`
 * which runs the Loop synchronously until this specific Future settles.
 */
final class CurlMultiHandler implements TransportInterface
{
    private readonly CurlFactory $factory;
    private readonly Loop $loop;

    /**
     * Per-handle state that the Loop needs to resolve responses.
     * Maps spl_object_id(\CurlHandle) → [headers ref, body Stream].
     * This data is set before the handle is registered with the Loop,
     * and read when the Loop signals completion.
     *
     * @var array<int, array{headers: string, body: Stream, request: Request, poolKey: string}>
     */
    private array $pendingHandles = [];

    public function __construct(
        ?CurlFactory $factory = null,
        ?Loop $loop = null,
    ) {
        $this->factory = $factory ?? new CurlFactory();
        $this->loop    = $loop ?? Loop::getInstance();
    }

    // ─── TransportInterface ───────────────────────────────────────────────────

    /**
     * Sends a request and blocks until the response is received.
     * Internally uses `sendAsync()` and runs the Loop until settled.
     */
    public function send(Request $request): Response
    {
        return $this->sendAsync($request)->get();
    }

    /**
     * Sends a request asynchronously and returns a Future immediately.
     * The Future resolves with the Response when the server replies.
     *
     * @return Future<Response>
     */
    public function sendAsync(Request $request): Future
    {
        $deferred = new Deferred();
        $future   = $deferred->getFuture();

        $headers = '';
        $body    = Stream::empty();
        $poolKey = $this->factory->getPoolKey($request);

        try {
            $curlHandle = $this->factory->create($request, $headers, $body);
        } catch (\Throwable $e) {
            $deferred->reject($e);

            return $future;
        }

        $handleId = spl_object_id($curlHandle);

        // Store the response buffers so we can build the Response when done
        $this->pendingHandles[$handleId] = [
            'headers' => &$headers,
            'body'    => $body,
            'request' => $request,
            'poolKey' => $poolKey,
        ];

        // Calculate timeout
        $timeoutSeconds = $request->getOption('timeout')?->requestSeconds ?? 0.0;

        // Register a completion handler BEFORE adding to the Loop.
        // When the Loop calls Deferred::resolve($responseData), we intercept
        // via the then() callback to build the actual Response object.
        $pendingHandles = &$this->pendingHandles;
        $factory        = $this->factory;

        // We use a raw deferred here and wrap it with a transformation layer.
        // The Loop resolves with ['handle' => CurlHandle, 'info' => array].
        // We transform that into a proper Response object here.
        $transportDeferred = new Deferred();
        $transportFuture   = $transportDeferred->getFuture();

        $transportFuture->then(function (array $rawData) use (
            $handleId, $deferred, &$pendingHandles, $factory
        ): void {
            $pending = $pendingHandles[$handleId] ?? null;

            if ($pending === null) {
                $deferred->tryReject(new \RuntimeException('Lost response buffer for handle ' . $handleId));

                return;
            }

            try {
                $response = $this->buildResponse(
                    rawHeaders: $pending['headers'],
                    bodyStream: $pending['body'],
                    curlHandle: $rawData['handle'],
                    info: $rawData['info'],
                );

                $deferred->tryResolve($response);
            } catch (\Throwable $e) {
                $deferred->tryReject($e);
            } finally {
                $factory->recycle($rawData['handle'], $pending['poolKey']);
                unset($pendingHandles[$handleId]);
            }
        })->catch(function (\Throwable $e) use ($handleId, $deferred, &$pendingHandles, $factory): void {
            $pending = $pendingHandles[$handleId] ?? null;

            if ($pending !== null && isset($rawData['handle'])) {
                $factory->recycle($rawData['handle'] ?? null, $pending['poolKey']);
            }

            unset($pendingHandles[$handleId]);
            $deferred->tryReject($e);
        });

        // Register with the Loop — this starts the async execution
        try {
            $this->loop->addHandle(
                curlHandle: $curlHandle,
                deferred: $transportDeferred,
                request: $request,
                timeoutSeconds: $timeoutSeconds,
            );
        } catch (\Throwable $e) {
            unset($this->pendingHandles[$handleId]);
            $deferred->tryReject($e);
        }

        return $future;
    }

    /**
     * Sends multiple requests concurrently and returns all responses.
     * This is the most efficient way to make many requests at once.
     *
     * @param list<Request> $requests
     * @return list<Response>
     * @throws \Throwable if any request fails.
     */
    public function sendAll(array $requests): array
    {
        $futures = array_map(fn(Request $r) => $this->sendAsync($r), $requests);

        return Future::all(...$futures);
    }

    /**
     * Sends multiple requests concurrently and returns all results (including failures).
     *
     * @param list<Request> $requests
     * @return list<array{status: string, value?: Response, reason?: \Throwable}>
     */
    public function sendAllSettled(array $requests): array
    {
        $futures = array_map(fn(Request $r) => $this->sendAsync($r), $requests);

        return Future::allSettled(...$futures);
    }

    public function isAvailable(): bool
    {
        return extension_loaded('curl')
            && function_exists('curl_multi_init')
            && PHP_MAJOR_VERSION >= 8
            && PHP_MINOR_VERSION >= 1;
    }

    public function name(): string
    {
        if (!$this->isAvailable()) {
            return 'curl-multi/unavailable';
        }

        $info = curl_version();

        return sprintf('curl-multi/%s+fibers', $info['version'] ?? 'unknown');
    }

    // ─── Private: Response Building ───────────────────────────────────────────

    /**
     * Constructs a Response from the accumulated cURL output.
     *
     * @param string      $rawHeaders The raw header bytes accumulated by the header function.
     * @param Stream      $bodyStream The stream containing the response body data.
     * @param \CurlHandle $curlHandle The completed cURL handle (for getinfo metadata).
     * @param array       $info       Pre-fetched curl_getinfo() result.
     */
    private function buildResponse(
        string      $rawHeaders,
        Stream      $bodyStream,
        \CurlHandle $curlHandle,
        array       $info = [],
    ): Response {
        if ($bodyStream->isSeekable()) {
            $bodyStream->rewind();
        }

        $statusCode = (int) ($info['http_code'] ?? 200);
        $blocks     = preg_split('/\r\n\r\n|\n\n/', trim($rawHeaders));
        $block      = end($blocks);

        if ($block === false) {
            return new Response($statusCode, [], $bodyStream);
        }

        $lines  = preg_split('/\r\n|\n/', trim($block));
        $status = array_shift($lines);

        preg_match('/HTTP\/(\S+)\s+\d+\s*(.*)?/', (string) $status, $matches);

        $version      = $matches[1] ?? '1.1';
        $reasonPhrase = trim($matches[2] ?? '');

        $headers = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$name, $value]    = explode(':', $line, 2);
            $headers[trim($name)][] = trim($value);
        }

        return new Response($statusCode, $headers, $bodyStream, $version, $reasonPhrase);
    }
}
