<?php

declare(strict_types=1);

namespace WireHttp;

use WireHttp\Async\Future;
use WireHttp\Async\Loop;
use WireHttp\Configuration\ClientConfig;
use WireHttp\Event\EventDispatcher;
use WireHttp\Http\Request;
use WireHttp\Http\Response;
use WireHttp\Middleware\MiddlewareInterface;
use WireHttp\Request\Builder\RequestBuilder;
use WireHttp\Security\LicensePipeline;
use WireHttp\Transport\Mock\MockResponseQueue;
use WireHttp\Transport\Mock\MockTransport;
use WireHttp\Transport\TransportInterface;

/**
 * Wire — The Static Facade Entry Point for WireHTTP
 *
 * `Wire` is a static façade that wraps a shared `Client` instance. It
 * provides the most ergonomic API for the vast majority of use cases —
 * zero boilerplate, just pure intent.
 *
 * Usage — Simplest possible:
 *   $users = Wire::get('https://api.example.com/users')->send()->json();
 *
 * Usage — Configuring the global client:
 *   Wire::configure(
 *       ClientConfig::create()
 *           ->withBaseUri('https://api.example.com')
 *           ->withDefaultHeader('Accept', 'application/json')
 *           ->withDefaultHeader('Authorization', "Bearer {$token}")
 *           ->withRetry(3)
 *           ->withTimeout(TimeoutConfig::fast())
 *   );
 *
 *   // All subsequent calls use the configured base URI
 *   $users  = Wire::get('/users')->send()->json();
 *   $orders = Wire::get('/orders')->send()->json();
 *
 * Usage — Concurrent requests with Fibers:
 *   $usersFuture  = Wire::get('/users')->sendAsync();
 *   $postsFuture  = Wire::get('/posts')->sendAsync();
 *   $configFuture = Wire::get('/config')->sendAsync();
 *
 *   [$users, $posts, $config] = Future::all($usersFuture, $postsFuture, $configFuture);
 *   // All 3 requests run in parallel. Total time ≈ max(t1, t2, t3), not sum.
 *
 * Usage — Testing with fake responses:
 *   Wire::fake(new MockResponseQueue()); // then push responses manually
 *
 *   // OR with a MockResponseQueue:
 *   $queue = new MockResponseQueue();
 *   $queue->push(MockResponseQueue::withJson(['id' => 1]));
 *   Wire::fake($queue);
 *
 * Usage — Per-request client with different config:
 *   $internalClient = Wire::with(
 *       ClientConfig::create()->withBaseUri('http://internal-service')
 *   );
 *   $result = $internalClient->get('/health')->send()->json();
 *
 * Architecture:
 * -------------
 * Wire is NOT a God object. It is purely a static access layer over `Client`.
 * The real logic lives in `Client`, `MiddlewareStack`, and the transports.
 * Wire exists for DX ergonomics — frameworks using DI containers should use
 * `Client` directly and bind it via the container.
 */
final class Wire
{
    /**
     * The shared singleton Client instance.
     * Initialized on first access or via configure().
     */
    private static ?Client $client = null;

    /**
     * The original (pre-fake) client, saved when Wire::fake() is called.
     * Restored by Wire::restoreFake().
     */
    private static ?Client $originalClient = null;

    // ─── Configuration ────────────────────────────────────────────────────────

    /**
     * Configures the global Wire client with the given ClientConfig.
     * All subsequent Wire::get(), Wire::post(), etc. calls use this configuration.
     *
     * This method replaces any previously configured client.
     */
    public static function configure(
        ClientConfig       $config,
        ?EventDispatcher   $events = null,
        ?TransportInterface $transport = null,
        MiddlewareInterface ...$middleware,
    ): void {
        self::$client = new Client($config, $transport, $events, ...$middleware);
    }

    /**
     * Returns the current global Client instance.
     * Creates a default Client instance on first call if none is configured.
     */
    public static function getClient(): Client
    {
        if (self::$client === null) {
            self::$client = new Client();
        }

        return self::$client;
    }

    /**
     * Replaces the global client with a new `Client` instance.
     * Useful for framework integration (e.g., inject a pre-built container-managed Client).
     */
    public static function setClient(Client $client): void
    {
        self::$client = $client;
    }

    // ─── HTTP Methods ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $query Optional query string parameters.
     */
    public static function get(string $uri, array $query = []): RequestBuilder
    {
        return self::getClient()->get($uri, $query);
    }

    public static function post(string $uri): RequestBuilder
    {
        return self::getClient()->post($uri);
    }

    public static function put(string $uri): RequestBuilder
    {
        return self::getClient()->put($uri);
    }

    public static function patch(string $uri): RequestBuilder
    {
        return self::getClient()->patch($uri);
    }

    public static function delete(string $uri): RequestBuilder
    {
        return self::getClient()->delete($uri);
    }

    public static function head(string $uri): RequestBuilder
    {
        return self::getClient()->head($uri);
    }

    public static function request(string $method, string $uri): RequestBuilder
    {
        return self::getClient()->request($method, $uri);
    }

    // ─── Ultra-Secure License Pipeline ───────────────────────────────────────

    /**
     * Creates an isolated, ultra-secure license verification pipeline.
     *
     * This is completely separate from the standard WireHTTP middleware stack.
     * It executes as a microscopic, dedicated pipeline designed to be
     * next-to-impossible to intercept or tamper with on the network.
     *
     * ─── What makes it secure? ────────────────────────────────────────────────
     *  - ISOLATED: Does not share transport, interceptors, or logging with
     *    the standard Wire pipeline. Nothing can accidentally hook into it.
     *  - SSL PINNED: Immune to Fiddler/Charles Proxy MITM attacks even with
     *    custom root certificates installed on the OS.
     *  - ENCRYPTED: Payloads can be encrypted with XSalsa20-Poly1305 (Sodium),
     *    rendering network sniffers useless.
     *  - SIGNED: Outgoing request body can be HMAC-signed.
     *  - VERIFIED: Server responses can be cryptographically verified
     *    (HMAC or Ed25519) before any data is returned to the developer.
     *  - ANTI-REPLAY: Automatic timestamp + nonce injection prevents request replay.
     *
     * ─── Usage Examples ──────────────────────────────────────────────────────
     *
     * Basic (just HTTPS):
     *   $result = Wire::license('https://api.server.com/verify')
     *       ->withPayload(['key' => 'ABCD-1234'])
     *       ->send();
     *
     * Maximum security (all protections):
     *   $result = Wire::license('https://api.server.com/verify')
     *       ->withPayload(['key' => 'ABCD-1234'])
     *       ->withSslPin('sha256//YourServerCertHash==')
     *       ->signRequestWith('hmac-secret')
     *       ->encryptWith('encryption-secret')
     *       ->verifyResponseWith(ResponseVerifier::withEd25519('base64PublicKey'))
     *       ->send();
     *
     *   if ($result->isValid()) {
     *       echo $result->get('license.plan'); // 'pro'
     *   }
     *
     * @param string $url The HTTPS URL of the license/verification server endpoint.
     */
    public static function license(string $url): LicensePipeline
    {
        return new LicensePipeline($url);
    }

    // ─── Per-Request Isolated Client ─────────────────────────────────────────

    /**
     * Returns a NEW isolated `Client` instance with the given config.
     * This does NOT affect the global Wire client.
     *
     * Use this for requests that need different config (different base URI,
     * auth, or timeout) without touching the global singleton.
     *
     * Usage:
     *   $adminClient = Wire::with(ClientConfig::create()
     *       ->withBaseUri('https://admin.example.com')
     *       ->withBearerAuth($adminToken)
     *   );
     *   $stats = $adminClient->get('/reports/summary')->send()->json();
     */
    public static function with(ClientConfig $config): Client
    {
        return new Client($config);
    }

    // ─── Testing / Faking ─────────────────────────────────────────────────────

    /**
     * Replaces the global transport with a MockTransport for testing.
     *
     * Accepts either:
     *   - A `MockResponseQueue` (full control over the response sequence).
     *   - A `MockTransport` instance (pre-configured).
     *   - null (creates an empty MockResponseQueue you can populate later).
     *
     * After calling Wire::fake(), Wire::restoreFake() restores the real client.
     *
     * Usage in PHPUnit:
     *   protected function setUp(): void {
     *       Wire::fake();
     *   }
     *
     *   protected function tearDown(): void {
     *       Wire::restoreFake();
     *   }
     *
     *   public function test_user_is_created(): void {
     *       Wire::getFakeQueue()->push(MockResponseQueue::withJson(['id' => 1]));
     *
     *       $sut = new UserService();
     *       $user = $sut->createUser('Alice');
     *
     *       $this->assertSame(1, $user->id);
     *       Wire::assertRequestCount(1);
     *   }
     *
     * @param MockResponseQueue|MockTransport|null $fake
     */
    public static function fake(MockResponseQueue|MockTransport|null $fake = null): MockTransport
    {
        // Save the real client so we can restore it later
        self::$originalClient = self::$client;

        $mock = match (true) {
            $fake instanceof MockTransport      => $fake,
            $fake instanceof MockResponseQueue  => new MockTransport($fake),
            default                             => new MockTransport(new MockResponseQueue()),
        };

        // Build a new Client using the mock transport, preserving the current config

        self::$client   = new Client(
            config: ClientConfig::create(),  // Fresh config — fakes should be clean
            transport: $mock,
        );

        return $mock;
    }

    /**
     * Restores the real Client after Wire::fake() was used.
     * Always call this in tearDown() after Wire::fake().
     */
    public static function restoreFake(): void
    {
        self::$client         = self::$originalClient;
        self::$originalClient = null;
    }

    /**
     * Returns the MockTransport currently installed as the fake, or null.
     */
    public static function getFakeTransport(): ?MockTransport
    {
        $transport = self::getClient()->getTransport();

        return $transport instanceof MockTransport ? $transport : null;
    }

    /**
     * Returns the MockResponseQueue from the installed fake transport.
     * Throws if no fake is active.
     */
    public static function getFakeQueue(): MockResponseQueue
    {
        $transport = self::getFakeTransport();

        if ($transport === null) {
            throw new \LogicException(
                'Wire::getFakeQueue() called but no MockTransport is active. ' .
                'Call Wire::fake() first.'
            );
        }

        return $transport->getQueue();
    }

    // ─── Testing Assertions ───────────────────────────────────────────────────

    /**
     * Asserts that exactly $count requests were made through the fake transport.
     *
     * @throws \LogicException   If Wire::fake() was not called first.
     * @throws \AssertionError   If the count does not match.
     */
    public static function assertRequestCount(int $count): void
    {
        self::getFakeQueue()->assertRequestCount($count);
    }

    /**
     * Asserts that the nth request (0-indexed) targeted the given URL.
     *
     * @throws \AssertionError
     */
    public static function assertRequestUrl(int $index, string $url): void
    {
        self::getFakeQueue()->assertRequestUrl($index, $url);
    }

    /**
     * Asserts that the nth request used the given HTTP method.
     *
     * @throws \AssertionError
     */
    public static function assertRequestMethod(int $index, string $method): void
    {
        self::getFakeQueue()->assertRequestMethod($index, $method);
    }

    // ─── Event Listener Shortcuts ─────────────────────────────────────────────

    /**
     * Registers a listener on the global client's EventDispatcher.
     *
     * Usage:
     *   Wire::on(RequestSendingEvent::class, function(RequestSendingEvent $e) {
     *       logger()->debug('Sending: ' . $e->getRequest()->getUri());
     *   });
     */
    public static function on(string $eventClass, callable $listener, int $priority = 0): void
    {
        self::getClient()->on($eventClass, $listener, $priority);
    }

    // ─── Loop Control ─────────────────────────────────────────────────────────

    /**
     * Runs the event loop until all pending Futures are settled.
     * Use this at the end of a script that uses sendAsync() without Fibers.
     *
     * Usage:
     *   $f1 = Wire::get('/a')->sendAsync();
     *   $f2 = Wire::get('/b')->sendAsync();
     *   Wire::run();
     *   // $f1 and $f2 are both resolved at this point
     */
    public static function run(): void
    {
        Loop::getInstance()->run();
    }

    // ─── Reset ────────────────────────────────────────────────────────────────

    /**
     * Resets the global Wire client to its uninitialized state.
     * A new default Client will be created on next access.
     * Useful in tests to ensure isolation.
     */
    public static function reset(): void
    {
        self::$client         = null;
        self::$originalClient = null;
    }
}
