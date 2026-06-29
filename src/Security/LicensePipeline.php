<?php

declare(strict_types=1);

namespace WireHttp\Security;

use WireHttp\Security\Exception\SecurityException;

/**
 * LicensePipeline — Ultra-Secure, Isolated HTTP Pipeline.
 *
 * Triggered exclusively by `Wire::license($url)`. This pipeline is completely
 * isolated from the standard WireHTTP middleware stack, interceptors, and
 * transports. It operates as a micro-pipeline with a fixed, non-extensible
 * execution order designed for maximum security.
 *
 * ─── Execution Order ────────────────────────────────────────────────────────
 *  1. [PREPARE]   Serialize + timestamp the developer's payload.
 *  2. [SIGN]      HMAC-sign the outgoing request body (optional).
 *  3. [ENCRYPT]   Encrypt the entire payload with Sodium XSalsa20-Poly1305 (optional).
 *  4. [TRANSPORT] Execute raw cURL with strict SSL Certificate Pinning.
 *  5. [VERIFY]    Cryptographically verify the server's response signature (optional).
 *  6. [DECRYPT]   Decrypt the response body (optional).
 *  7. [RETURN]    Wrap into LicensePipelineResult and return to developer.
 *
 * ─── SSL Pinning ─────────────────────────────────────────────────────────────
 * Uses CURLOPT_PINNEDPUBLICKEY. This is immune to:
 *  - Fiddler / Charles Proxy with custom root CA certificates
 *  - Local `/etc/hosts` DNS redirects
 *  - System-level MITM certificates
 *
 * The pin is always the SHA-256 hash of the server's public key, formatted as:
 *   "sha256//Base64EncodedSHA256Hash=="
 *
 * ─── Anti-Replay ─────────────────────────────────────────────────────────────
 * The request payload automatically includes:
 *  - `_wire_timestamp` (Unix timestamp): servers can reject stale requests.
 *  - `_wire_nonce` (random hex): prevents two identical payloads producing
 *    the same encrypted blob (even without sodium encryption).
 *
 * ─── Developer Usage ─────────────────────────────────────────────────────────
 * Basic (HTTPS only, no extra security):
 *   $result = Wire::license('https://api.server.com/verify')
 *       ->withPayload(['license_key' => 'ABCD-1234'])
 *       ->send();
 *
 * Medium (SSL Pin + HMAC request signing):
 *   $result = Wire::license('https://api.server.com/verify')
 *       ->withPayload(['license_key' => 'ABCD-1234'])
 *       ->withSslPin('sha256//AbCdEfGhIjKlMnOpQrStUvWxYz==')
 *       ->signRequestWith('my-hmac-secret')
 *       ->send();
 *
 * Maximum (All protections enabled):
 *   $result = Wire::license('https://api.server.com/verify')
 *       ->withPayload(['license_key' => 'ABCD-1234'])
 *       ->withSslPin('sha256//AbCdEfGhIjKlMnOpQrStUvWxYz==')
 *       ->signRequestWith('my-hmac-secret')
 *       ->encryptWith('my-encryption-secret')
 *       ->verifyResponseWith(ResponseVerifier::withEd25519('base64PublicKey'))
 *       ->send();
 */
final class LicensePipeline
{
    // ─── State ────────────────────────────────────────────────────────────────

    /** @var array<string, mixed> */
    private array $payload = [];

    /** @var array<string, string> */
    private array $headers = [
        'Content-Type' => 'application/json',
        'Accept'       => 'application/json',
        'User-Agent'   => 'WireHTTP-SecurePipeline/1.0',
    ];

    private ?string          $sslPin          = null;
    private ?string          $hmacSecret      = null;
    private ?SodiumCryptoBox $cryptoBox       = null;
    private ?ResponseVerifier $responseVerifier = null;
    private string           $method          = 'POST';
    private int              $timeoutSeconds  = 15;
    private bool             $verifyPeer      = true;
    private bool             $injectAntiReplay = true;

    public function __construct(private readonly string $url) {}

    // ─── Fluent Builder ──────────────────────────────────────────────────────

    /**
     * Sets the data payload to be sent to the server.
     *
     * This data will automatically have `_wire_timestamp` and `_wire_nonce`
     * injected for anti-replay protection before being sent.
     *
     * @param array<string, mixed> $payload Arbitrary key-value pairs.
     */
    public function withPayload(array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    /**
     * Adds a single field to the payload.
     * Chainable for adding individual fields.
     *
     * @param string $key   Payload key.
     * @param mixed  $value Payload value.
     */
    public function withField(string $key, mixed $value): self
    {
        $this->payload[$key] = $value;

        return $this;
    }

    /**
     * Adds a custom HTTP header to the request.
     *
     * Use this to send an API key, a developer-specific correlation ID,
     * or any other header your license server expects.
     *
     * @param string $name  Header name.
     * @param string $value Header value.
     */
    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /**
     * Sets the HTTP method for the license request (default: POST).
     *
     * Most license servers expect POST with a JSON body, but some use GET.
     *
     * @param 'GET'|'POST'|'PUT'|'PATCH' $method
     */
    public function withMethod(string $method): self
    {
        $this->method = strtoupper($method);

        return $this;
    }

    /**
     * Enables strict SSL Certificate Pinning for this request.
     *
     * This completely defeats MITM proxy tools (Fiddler, Charles Proxy, mitmproxy)
     * even when they have installed custom root CA certificates into the OS.
     *
     * ─── How to get your server's pin ────────────────────────────────────────
     * Run the following command against your license server domain:
     *
     *   openssl s_client -connect api.yourserver.com:443 2>/dev/null \
     *     | openssl x509 -noout -pubkey \
     *     | openssl pkey -pubin -outform DER \
     *     | openssl dgst -sha256 -binary \
     *     | base64
     *
     * Then prefix it with "sha256//":
     *   ->withSslPin('sha256//AbCdEfGhIjKlMnOpQrStUvWxYz==')
     *
     * ─── Multiple Pins ────────────────────────────────────────────────────────
     * To support certificate rotation, provide multiple pins separated by ';':
     *   ->withSslPin('sha256//PinA==;sha256//PinB==')
     *
     * @param string $pin SHA-256 public key pin in "sha256//base64hash" format.
     */
    public function withSslPin(string $pin): self
    {
        $this->sslPin = $pin;

        return $this;
    }

    /**
     * Signs the outgoing request body using HMAC-SHA256.
     *
     * The signature is computed over:
     *   HMAC-SHA256(secretKey, timestamp + "." + requestBody)
     *
     * And sent in the `X-Wire-Signature` header.
     * Your server-side verification code should recompute this hash
     * and compare it using a constant-time comparison function.
     *
     * @param string $secret     Shared HMAC secret known to both client and server.
     * @param string $algorithm  HMAC hash algorithm (default: 'sha256').
     */
    public function signRequestWith(string $secret, string $algorithm = 'sha256'): self
    {
        $this->hmacSecret = $secret;
        // Store algorithm for use during signing
        $this->headers['X-Wire-Sign-Algorithm'] = $algorithm;

        return $this;
    }

    /**
     * Enables symmetric payload encryption using libsodium XSalsa20-Poly1305.
     *
     * The entire JSON body is encrypted into an opaque binary blob before
     * being sent over the network. Even if SSL is somehow compromised, the
     * attacker sees only binary noise — not your license data.
     *
     * The `Content-Type` is automatically changed to `application/octet-stream`
     * when encryption is enabled, so the server knows to decrypt the body.
     *
     * ─── Server-Side Decryption ──────────────────────────────────────────────
     * The server must use the same secret key and the same Sodium parameters:
     *   `sodium_crypto_secretbox_open($body, $nonce, $key)`
     *
     * Wire format: base64( nonce[24] || ciphertext )
     *
     * @param string $secret Shared encryption secret. Any string is acceptable;
     *                       internally derived into a 256-bit key via BLAKE2b.
     *
     * @throws SecurityException If the sodium extension is not available.
     */
    public function encryptWith(string $secret): self
    {
        $this->cryptoBox = new SodiumCryptoBox($secret);
        $this->headers['Content-Type'] = 'application/octet-stream';
        $this->headers['X-Wire-Encrypted'] = '1';

        return $this;
    }

    /**
     * Enables cryptographic verification of the server's response signature.
     *
     * After receiving the response, WireHTTP will validate the signature
     * before returning any data to you. If the signature is missing, invalid,
     * or the response timestamp is outside the anti-replay window, a
     * `SecurityException` is thrown and no response data is returned.
     *
     * This defeats "fake license server" attacks even if someone spoofs your
     * domain via DNS, because they cannot forge the response signature without
     * the server's private key.
     *
     * Supported verifiers:
     *   ResponseVerifier::withHmac('secret')          → HMAC-SHA256 (symmetric)
     *   ResponseVerifier::withEd25519('base64pubkey') → Ed25519 (asymmetric, recommended)
     *
     * @param ResponseVerifier $verifier Pre-configured verifier instance.
     */
    public function verifyResponseWith(ResponseVerifier $verifier): self
    {
        $this->responseVerifier = $verifier;

        return $this;
    }

    /**
     * Configures the request timeout in seconds (default: 15).
     *
     * @param int $seconds Maximum number of seconds to wait for a response.
     */
    public function withTimeout(int $seconds): self
    {
        $this->timeoutSeconds = $seconds;

        return $this;
    }

    /**
     * Disables SSL peer certificate verification.
     *
     * ⚠️  WARNING: Only use this during local development with self-signed certs.
     * Never disable SSL verification in production — it completely defeats TLS.
     *
     * If you disable this AND provide an SSL pin, the pin takes precedence;
     * `verifyPeer` only controls the OS CA trust store verification.
     */
    public function withoutSslVerification(): self
    {
        $this->verifyPeer = false;

        return $this;
    }

    /**
     * Disables automatic injection of `_wire_timestamp` and `_wire_nonce`.
     *
     * Anti-replay injection is enabled by default. Disable it only if your
     * server does not support these fields and you have alternative replay
     * protection in place.
     */
    public function withoutAntiReplay(): self
    {
        $this->injectAntiReplay = false;

        return $this;
    }

    // ─── Execution ───────────────────────────────────────────────────────────

    /**
     * Executes the ultra-secure license pipeline.
     *
     * Pipeline Execution Order:
     *  1. Build payload (inject anti-replay fields)
     *  2. Sign outgoing request (HMAC)
     *  3. Encrypt payload (Sodium)
     *  4. Execute raw cURL with SSL Pinning
     *  5. Verify server response signature
     *  6. Decrypt response body
     *  7. Return LicensePipelineResult
     *
     * @throws SecurityException On any security check failure.
     * @throws \RuntimeException On network or cURL failure.
     */
    public function send(): LicensePipelineResult
    {
        // ─── Step 1: Build Payload ────────────────────────────────────────────
        $payload = $this->payload;

        if ($this->injectAntiReplay) {
            $payload['_wire_timestamp'] = time();
            $payload['_wire_nonce']     = bin2hex(random_bytes(16));
        }

        $bodyJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        // ─── Step 2: HMAC Sign the Outgoing Request ───────────────────────────
        if ($this->hmacSecret !== null) {
            $algorithm = $this->headers['X-Wire-Sign-Algorithm'] ?? 'sha256';
            unset($this->headers['X-Wire-Sign-Algorithm']); // Don't leak this header

            $timestamp                       = $payload['_wire_timestamp'] ?? time();
            $signingInput                    = $timestamp . '.' . $bodyJson;
            $this->headers['X-Wire-Signature'] = 'sha256=' . base64_encode(
                hash_hmac($algorithm, $signingInput, $this->hmacSecret, binary: true)
            );
            $this->headers['X-Wire-Timestamp'] = (string) $timestamp;
        }

        // ─── Step 3: Encrypt Payload ──────────────────────────────────────────
        if ($this->cryptoBox !== null) {
            $body = $this->cryptoBox->encrypt($bodyJson);
        } else {
            $body = $bodyJson;
        }

        // ─── Step 4: Execute cURL (Isolated Transport) ────────────────────────
        [$responseBody, $statusCode, $responseHeaders] = $this->executeRawCurl($body);

        // ─── Step 5: Verify Server Response Signature ─────────────────────────
        if ($this->responseVerifier !== null) {
            $this->responseVerifier->verify($responseBody, $responseHeaders, $this->url);
        }

        // ─── Step 6: Decrypt Response Body ────────────────────────────────────
        $isEncryptedResponse = ($responseHeaders['x-wire-encrypted'] ?? '') === '1'
            || ($responseHeaders['X-Wire-Encrypted'] ?? '') === '1';

        if ($this->cryptoBox !== null && $isEncryptedResponse) {
            $responseBody = $this->cryptoBox->decrypt($responseBody, $this->url);
        }

        // ─── Step 7: Return Result ────────────────────────────────────────────
        return new LicensePipelineResult($responseBody, $statusCode);
    }

    // ─── Private: Raw cURL Transport ─────────────────────────────────────────

    /**
     * Executes a raw cURL request with SSL pinning support.
     *
     * This is a completely isolated transport — it does NOT use CurlTransport,
     * MiddlewareStack, or any other WireHTTP component. This intentional
     * isolation means the license request is immune to any global hooks,
     * middleware, or logging that could expose sensitive license data.
     *
     * @param  string $body The serialized (and possibly encrypted) request body.
     * @return array{0: string, 1: int, 2: array<string, string>}
     *               [responseBody, statusCode, responseHeaders]
     *
     * @throws SecurityException If SSL pin verification fails.
     * @throws \RuntimeException If the cURL request fails.
     */
    private function executeRawCurl(string $body): array
    {
        $ch = curl_init();

        // ─── Base cURL Options ─────────────────────────────────────────────
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeoutSeconds),
            CURLOPT_FOLLOWLOCATION => true,   // Follow HTTP redirects
            CURLOPT_MAXREDIRS      => 5,      // Max redirect hops
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS, // Prefer HTTP/2
            CURLOPT_ENCODING       => '',                      // Accept all encodings

            // ─── TLS Hardening ────────────────────────────────────────────
            CURLOPT_SSL_VERIFYPEER => $this->verifyPeer,
            CURLOPT_SSL_VERIFYHOST => $this->verifyPeer ? 2 : 0,
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2, // Minimum TLS 1.2

            // ─── Cipher Suite Restrictions ───────────────────────────────
            // Only allow strong AEAD cipher suites with forward secrecy.
            // This blocks weak ciphers (RC4, DES, 3DES, CBC-based suites).
            CURLOPT_SSL_CIPHER_LIST => implode(':', [
                'TLS_AES_256_GCM_SHA384',
                'TLS_CHACHA20_POLY1305_SHA256',
                'TLS_AES_128_GCM_SHA256',
                'ECDHE-ECDSA-AES256-GCM-SHA384',
                'ECDHE-RSA-AES256-GCM-SHA384',
                'ECDHE-ECDSA-CHACHA20-POLY1305',
                'ECDHE-RSA-CHACHA20-POLY1305',
            ]),
        ]);

        // ─── SSL Certificate Pinning ───────────────────────────────────────
        if ($this->sslPin !== null) {
            curl_setopt($ch, CURLOPT_PINNEDPUBLICKEY, $this->sslPin);
        }

        // ─── HTTP Method & Body ────────────────────────────────────────────
        if ($this->method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($this->method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $this->method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        // ─── Headers ──────────────────────────────────────────────────────
        // Only set Content-Length and Content-Type for requests that have a body.
        if ($this->method !== 'GET') {
            $this->headers['Content-Length'] = (string) strlen($body);
        } else {
            // Remove body-related headers so the GET request stays clean
            unset($this->headers['Content-Type'], $this->headers['Content-Length'], $this->headers['X-Wire-Encrypted']);
        }

        $headerLines = [];
        foreach ($this->headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);

        // ─── Capture Response Headers ──────────────────────────────────────
        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, string $headerLine) use (&$responseHeaders): int {
            $parts = explode(':', $headerLine, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($headerLine);
        });

        // ─── Execute ──────────────────────────────────────────────────────
        $responseBody = curl_exec($ch);
        $curlError    = curl_error($ch);
        $curlErrno    = curl_errno($ch);
        $statusCode   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        // ─── Error Handling ───────────────────────────────────────────────
        if ($responseBody === false || $curlErrno !== 0) {
            // CURLE_SSL_PINNEDPUBKEYNOTMATCH (90) specifically indicates a pin mismatch.
            if ($curlErrno === 90) {
                throw SecurityException::pinMismatch($this->url);
            }

            throw new \RuntimeException(
                "LicensePipeline cURL error [{$curlErrno}] for [{$this->url}]: {$curlError}"
            );
        }

        return [$responseBody, $statusCode, $responseHeaders];
    }
}
