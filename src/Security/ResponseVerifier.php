<?php

declare(strict_types=1);

namespace WireHttp\Security;

use WireHttp\Security\Exception\SecurityException;

/**
 * ResponseVerifier — Cryptographic Verification of Server Responses
 *
 * After WireHTTP receives a response from the server, this class verifies
 * that the response genuinely came from the expected server and was not
 * modified or injected by an attacker.
 *
 * ─── How it works ────────────────────────────────────────────────────────────
 * The server must sign its response body using an HMAC-SHA256 secret or an
 * Ed25519 private key. The developer provides WireHTTP with the corresponding
 * verification material (the HMAC shared secret, or the Ed25519 public key).
 *
 * When a response arrives, ResponseVerifier:
 *  1. Reads the signature from the configured response header.
 *  2. Recomputes the expected signature from the raw response body.
 *  3. Compares using a timing-safe comparison (`hash_equals`) to prevent
 *     timing-based oracle attacks.
 *  4. Validates the response timestamp against the anti-replay tolerance window.
 *  5. Throws a `SecurityException` if any check fails.
 *
 * ─── Anti-Replay Protection ──────────────────────────────────────────────────
 * If the server includes an `X-Wire-Response-Timestamp` header, this verifier
 * will reject responses whose timestamp is outside the tolerance window
 * (default: 300 seconds). This prevents an attacker from capturing a valid
 * server response and replaying it to the client at a later time.
 *
 * ─── Supported Algorithms ────────────────────────────────────────────────────
 *  - HMAC-SHA256 (symmetric shared secret)
 *  - HMAC-SHA512 (higher security symmetric shared secret)
 *  - Ed25519 (asymmetric — server holds private key, client holds public key)
 *    Requires PHP >= 8.1 and the sodium extension.
 */
final class ResponseVerifier
{
    private const TIMESTAMP_HEADER = 'X-Wire-Response-Timestamp';
    private const SIGNATURE_HEADER = 'X-Wire-Response-Signature';

    /**
     * @param string      $secret          Shared HMAC secret or base64-encoded Ed25519 public key.
     * @param string      $algorithm       'hmac-sha256' | 'hmac-sha512' | 'ed25519'
     * @param int         $replayTolerance Max allowed clock skew in seconds for anti-replay.
     * @param string      $signatureHeader Custom response header carrying the signature.
     * @param string      $timestampHeader Custom response header carrying the timestamp.
     */
    public function __construct(
        private readonly string $secret,
        private readonly string $algorithm       = 'hmac-sha256',
        private readonly int    $replayTolerance = 300,
        private readonly string $signatureHeader = self::SIGNATURE_HEADER,
        private readonly string $timestampHeader = self::TIMESTAMP_HEADER,
    ) {
        if (!extension_loaded('sodium') && $algorithm === 'ed25519') {
            throw SecurityException::sodiumMissing();
        }
    }

    /**
     * Verifies the response signature and anti-replay timestamp.
     *
     * @param  string               $responseBody    The raw response body string.
     * @param  array<string, string> $responseHeaders All response headers (name => value).
     * @param  string               $url             The request URL, used in exception messages.
     *
     * @throws SecurityException On any verification failure.
     */
    public function verify(string $responseBody, array $responseHeaders, string $url = ''): void
    {
        // Normalize header names for case-insensitive lookup
        $normalized = [];
        foreach ($responseHeaders as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        $signature = $normalized[strtolower($this->signatureHeader)] ?? '';

        if ($signature === '') {
            throw SecurityException::invalidResponseSignature($url);
        }

        // --- Anti-Replay: Validate timestamp if present ---
        $timestampRaw = $normalized[strtolower($this->timestampHeader)] ?? null;
        if ($timestampRaw !== null) {
            $timestamp = (int) $timestampRaw;
            $delta     = abs(time() - $timestamp);
            if ($delta > $this->replayTolerance) {
                throw SecurityException::replayDetected($url, $delta);
            }
        }

        // --- Signature Verification ---
        $isValid = match ($this->algorithm) {
            'hmac-sha256' => $this->verifyHmac($responseBody, $signature, 'sha256'),
            'hmac-sha512' => $this->verifyHmac($responseBody, $signature, 'sha512'),
            'ed25519'     => $this->verifyEd25519($responseBody, $signature),
            default       => throw new \InvalidArgumentException(
                "Unsupported signature algorithm: [{$this->algorithm}]."
            ),
        };

        if (!$isValid) {
            throw SecurityException::invalidResponseSignature($url);
        }
    }

    /**
     * Verifies an HMAC signature using a timing-safe comparison.
     */
    private function verifyHmac(string $body, string $signature, string $algo): bool
    {
        $expected = base64_encode(hash_hmac($algo, $body, $this->secret, binary: true));

        return hash_equals($expected, $signature);
    }

    /**
     * Verifies an Ed25519 signature using sodium.
     *
     * The server signs the raw response body bytes with its Ed25519 private key.
     * The developer supplies the base64-encoded public key to WireHTTP.
     * WireHTTP uses the public key to verify — no secret is ever needed client-side.
     */
    private function verifyEd25519(string $body, string $signature): bool
    {
        $publicKey = base64_decode($this->secret, strict: true);
        $sigBytes  = base64_decode($signature, strict: true);

        if ($publicKey === false || $sigBytes === false) {
            return false;
        }

        // sodium_crypto_sign_verify_detached returns true only if signature is valid.
        return sodium_crypto_sign_verify_detached($sigBytes, $body, $publicKey);
    }

    // ─── Named Constructors ──────────────────────────────────────────────────

    /**
     * Creates a verifier using HMAC-SHA256 (symmetric shared secret).
     * Use this when both client and server share a secret key.
     */
    public static function withHmac(string $secret, int $replayTolerance = 300): self
    {
        return new self($secret, 'hmac-sha256', $replayTolerance);
    }

    /**
     * Creates a verifier using Ed25519 public-key cryptography.
     * Use this when only the server knows the private key.
     * The client only needs the base64-encoded public key.
     *
     * This is the most secure option — even if WireHTTP's config is leaked,
     * the attacker cannot forge responses because they don't have the private key.
     *
     * @param string $base64PublicKey The Ed25519 public key, base64-encoded.
     */
    public static function withEd25519(string $base64PublicKey, int $replayTolerance = 300): self
    {
        return new self($base64PublicKey, 'ed25519', $replayTolerance);
    }
}
