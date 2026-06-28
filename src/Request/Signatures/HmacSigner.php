<?php

declare(strict_types=1);

namespace WireHttp\Request\Signatures;

use WireHttp\Http\Request;

/**
 * HmacSigner — HMAC-SHA256 Request Signing
 *
 * Generates a cryptographic signature for an HTTP request and attaches
 * it as a header. Used by APIs that require signed requests to verify
 * request authenticity and detect tampering (e.g., Stripe, GitHub Webhooks,
 * AWS Signature v4-style, Twilio).
 *
 * Signature Algorithm:
 * --------------------
 * The signature is computed over a "signing string" — a canonical
 * representation of the request that includes:
 *   1. The HTTP method (uppercased)
 *   2. The full URI
 *   3. A timestamp (Unix epoch seconds)
 *   4. The request body (empty string if no body)
 *
 * Signing String format:
 *   "{METHOD}\n{URI}\n{TIMESTAMP}\n{BODY_HEX_SHA256}"
 *
 * The final signature is HMAC-SHA256(signingString, secretKey), base64-encoded.
 *
 * Replay Attack Prevention:
 * -------------------------
 * The timestamp is included in the signing string and also added to the request
 * headers. The server validates that the timestamp is within a configurable
 * tolerance window (e.g., ±5 minutes). Requests with timestamps outside this
 * window are rejected — preventing replay attacks where an attacker re-sends
 * a captured valid request later.
 *
 * Custom Header Schema:
 * ---------------------
 * By default, the signer adds:
 *   X-Wire-Timestamp: 1704067200
 *   X-Wire-Signature: sha256=base64encodedHMAC...
 *
 * These can be customized via constructor parameters.
 *
 * Usage:
 *   $signer = new HmacSigner(secret: 'my-secret-key');
 *   $signedRequest = $signer->sign($request);
 *
 * Usage with custom headers (to match a specific API's requirements):
 *   $signer = new HmacSigner(
 *       secret: $stripeSecret,
 *       timestampHeader: 'Stripe-Timestamp',
 *       signatureHeader: 'Stripe-Signature',
 *       signaturePrefix: 'v1=',
 *   );
 */
final class HmacSigner
{
    public function __construct(
        /** The shared secret key used to compute the HMAC. */
        private readonly string $secret,

        /** The HMAC hash algorithm to use. */
        private readonly string $algorithm = 'sha256',

        /** The request header to send the timestamp in. */
        private readonly string $timestampHeader = 'X-Wire-Timestamp',

        /** The request header to send the signature in. */
        private readonly string $signatureHeader = 'X-Wire-Signature',

        /**
         * An optional prefix added before the base64 signature in the header.
         * Example: 'sha256=' produces 'X-Wire-Signature: sha256=abc123...'
         */
        private readonly string $signaturePrefix = 'sha256=',

        /**
         * Whether to include the request body in the signing string.
         * Set to false for GET requests or APIs that don't sign the body.
         */
        private readonly bool $includeBody = true,
    ) {
        if (!in_array($algorithm, hash_hmac_algos(), strict: true)) {
            throw new \InvalidArgumentException(
                sprintf('Unsupported HMAC algorithm "%s". Available: %s.', $algorithm, implode(', ', hash_hmac_algos()))
            );
        }
    }

    /**
     * Signs the request and returns a new Request with the signature headers added.
     *
     * This method is pure (no side effects) — it returns a new immutable Request
     * object. The original request is not modified.
     *
     * @param int|null $timestamp Unix timestamp (defaults to current time). Override in tests.
     */
    public function sign(Request $request, ?int $timestamp = null): Request
    {
        $timestamp  ??= time();
        $signingString = $this->buildSigningString($request, $timestamp);
        $signature     = $this->signaturePrefix . base64_encode(
            hash_hmac($this->algorithm, $signingString, $this->secret, binary: true)
        );

        return $request
            ->withHeader($this->timestampHeader, (string) $timestamp)
            ->withHeader($this->signatureHeader, $signature);
    }

    /**
     * Verifies whether the given signature header on a request is valid.
     * Computes what the correct signature should be and compares using
     * `hash_equals()` (timing-safe comparison, prevents timing attacks).
     *
     * @param Request $request   The request to verify.
     * @param int     $tolerance Maximum allowed clock skew in seconds (default: 300s = 5 minutes).
     * @return bool True if the signature is valid.
     */
    public function verify(Request $request, int $tolerance = 300): bool
    {
        $rawTimestamp = $request->getHeaderLine($this->timestampHeader);
        $givenSig     = $request->getHeaderLine($this->signatureHeader);

        if ($rawTimestamp === '' || $givenSig === '') {
            return false;
        }

        $timestamp = (int) $rawTimestamp;

        // Reject stale/future requests (anti-replay)
        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $signingString = $this->buildSigningString($request, $timestamp);
        $expectedSig   = $this->signaturePrefix . base64_encode(
            hash_hmac($this->algorithm, $signingString, $this->secret, binary: true)
        );

        return hash_equals($expectedSig, $givenSig);
    }

    /**
     * Builds the canonical signing string from the request components.
     */
    private function buildSigningString(Request $request, int $timestamp): string
    {
        $method = strtoupper($request->getMethod());
        $uri    = (string) $request->getUri();

        $bodyHash = '';

        if ($this->includeBody) {
            $body = $request->getBody();

            if ($body->isSeekable()) {
                $body->rewind();
            }

            $bodyHash = hash('sha256', $body->getContents());
        }

        return implode("\n", [$method, $uri, $timestamp, $bodyHash]);
    }

    /**
     * Creates an HmacSigner pre-configured to match GitHub Webhook signature format.
     * GitHub uses: X-Hub-Signature-256: sha256=<hex_hmac>
     */
    public static function github(string $secret): static
    {
        return new static(
            secret: $secret,
            algorithm: 'sha256',
            timestampHeader: 'X-Hub-Delivery', // GitHub uses delivery ID, not timestamp
            signatureHeader: 'X-Hub-Signature-256',
            signaturePrefix: 'sha256=',
            includeBody: true,
        );
    }

    /**
     * Creates an HmacSigner compatible with Stripe Webhook verification.
     * Stripe format: Stripe-Signature: t=<timestamp>,v1=<signature>
     */
    public static function stripe(string $secret): static
    {
        return new static(
            secret: $secret,
            algorithm: 'sha256',
            timestampHeader: 'X-Stripe-Timestamp',
            signatureHeader: 'Stripe-Signature',
            signaturePrefix: 'v1=',
            includeBody: true,
        );
    }
}
