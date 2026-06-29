<?php

declare(strict_types=1);

namespace WireHttp\Security\Exception;

use WireHttp\Exceptions\WireHttpException;

/**
 * SecurityException — Thrown when the Ultra-Secure Pipeline detects an attack.
 *
 * Scenarios that trigger this exception:
 *  - SSL Certificate Pin mismatch (MITM proxy detected)
 *  - Server response signature is missing, malformed, or invalid
 *  - Server response timestamp is outside the anti-replay tolerance window
 *  - Decryption failed (response payload has been tampered with in-transit)
 *  - Sodium extension is not available on this PHP installation
 *
 * This is a terminal exception. When thrown, the pipeline is completely
 * aborted. No response data is ever returned to the developer.
 *
 * Security Design Note:
 * ---------------------
 * The exception message deliberately avoids exposing cryptographic
 * internals (e.g., the expected hash) to prevent oracle attacks.
 * The inherited 'context' array can hold internal debug data for logging only.
 */
class SecurityException extends WireHttpException
{
    /**
     * @param string  $message  Human-readable security violation description.
     * @param array<string, mixed> $securityContext  Internal debug context (do NOT expose to end users).
     */
    public function __construct(
        string $message,
        array $securityContext = [],
        ?\Throwable $previous = null,
    ) {
        // Call WireHttpException with positional args to avoid named-param ordering issues.
        // $context is the 6th parameter: (message, code, previous, request, response, context)
        parent::__construct($message, 0, $previous, null, null, $securityContext);
    }

    /**
     * Returns internal context data for logging.
     * Do NOT expose this to end users or HTTP responses.
     *
     * @return array<string, mixed>
     */
    public function getSecurityContext(): array
    {
        return $this->context;
    }

    // ─── Named Constructors ──────────────────────────────────────────────────

    public static function pinMismatch(string $url): self
    {
        return new self(
            message: "SSL certificate pin mismatch for [{$url}]. Connection terminated — possible MITM attack.",
            securityContext: ['url' => $url, 'reason' => 'pin_mismatch'],
        );
    }

    public static function invalidResponseSignature(string $url): self
    {
        return new self(
            message: "Server response signature verification failed for [{$url}]. The response may have been tampered with.",
            securityContext: ['url' => $url, 'reason' => 'invalid_response_signature'],
        );
    }

    public static function replayDetected(string $url, int $delta): self
    {
        return new self(
            message: "Anti-replay check failed for [{$url}]. Response timestamp is {$delta}s outside the tolerance window.",
            securityContext: ['url' => $url, 'reason' => 'replay_attack', 'delta_seconds' => $delta],
        );
    }

    public static function decryptionFailed(string $url): self
    {
        return new self(
            message: "Payload decryption failed for [{$url}]. The response body appears to have been tampered with in-transit.",
            securityContext: ['url' => $url, 'reason' => 'decryption_failed'],
        );
    }

    public static function sodiumMissing(): self
    {
        return new self(
            message: 'The PHP sodium extension is required for LicensePipeline encryption but is not loaded. ' .
                     'Install the sodium extension (php-sodium) or enable it in php.ini.',
            securityContext: ['reason' => 'sodium_extension_missing'],
        );
    }

    public static function encryptionKeyInvalid(string $reason): self
    {
        return new self(
            message: "Encryption key is invalid: {$reason}",
            securityContext: ['reason' => 'invalid_encryption_key'],
        );
    }
}
