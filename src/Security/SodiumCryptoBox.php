<?php

declare(strict_types=1);

namespace WireHttp\Security;

use WireHttp\Security\Exception\SecurityException;

/**
 * SodiumCryptoBox — Authenticated Encryption / Decryption using libsodium.
 *
 * Uses XSalsa20-Poly1305 (via `sodium_crypto_secretbox`) for AEAD encryption.
 *
 * ─── Why ChaCha20 / XSalsa20 and not AES? ───────────────────────────────────
 * PHP's sodium extension uses XSalsa20-Poly1305 for `crypto_secretbox`. This
 * is a "combined mode" AEAD cipher: it BOTH encrypts AND authenticates the
 * ciphertext. This means:
 *
 *  1. The encrypted blob is confidential — a network sniffer sees binary noise.
 *  2. If a hacker alters even a single bit of the ciphertext, decryption fails
 *     with an authentication error before any plaintext is returned.
 *
 * This is fundamentally more secure than AES-CBC (which has no built-in
 * authentication and is vulnerable to padding oracle attacks).
 *
 * ─── Key Derivation ─────────────────────────────────────────────────────────
 * The developer provides a raw shared secret string. This class uses
 * `sodium_crypto_generichash` (BLAKE2b) to derive a fixed-length 256-bit key
 * from that secret, which is the exact key size required by `crypto_secretbox`.
 * This means any string — short, long, unicode — can be used as a secret.
 *
 * ─── Wire Format ────────────────────────────────────────────────────────────
 * The encrypted payload wire format is:
 *   [ 24-byte nonce ][ N-byte ciphertext ]
 *
 * The nonce is randomly generated for each message, prepended to the ciphertext,
 * and sent over the wire as a single base64-encoded binary blob. The receiver
 * reads the first 24 bytes as the nonce, then decrypts the remainder.
 *
 * A new nonce per message guarantees that even identical plaintexts produce
 * completely different ciphertexts — preventing known-plaintext attacks.
 */
final class SodiumCryptoBox
{
    /**
     * Key length required by sodium_crypto_secretbox: 32 bytes.
     * Using an integer literal here intentionally — if we referenced
     * \SODIUM_CRYPTO_SECRETBOX_KEYBYTES directly as a class constant,
     * PHP would fail to define the class when sodium is NOT loaded.
     */
    private const KEY_BYTES = 32;

    /**
     * Nonce length required by sodium_crypto_secretbox: 24 bytes.
     * Same rationale as KEY_BYTES — using a literal avoids a fatal
     * class-definition error on systems without the sodium extension.
     */
    private const NONCE_BYTES = 24;

    /** Blake2b output size used for key derivation (must equal KEY_BYTES). */
    private const KDF_OUTPUT_BYTES = self::KEY_BYTES;

    /** Derived 32-byte cryptographic key. */
    private readonly string $key;

    /**
     * @param string $secret Shared secret known to both client and server.
     *                       Any length and character set is supported.
     *
     * @throws SecurityException If sodium extension is not installed.
     */
    public function __construct(string $secret)
    {
        if (!extension_loaded('sodium')) {
            throw SecurityException::sodiumMissing();
        }

        // Derive a fixed-size 256-bit key from the developer's secret string.
        // BLAKE2b is used here purely as a key derivation function (KDF),
        // not as a password hasher. No salt is needed here because the
        // randomness comes from the per-message nonce, not from the key itself.
        $this->key = sodium_crypto_generichash($secret, key: '', length: self::KDF_OUTPUT_BYTES);
    }

    /**
     * Encrypts a plaintext payload and returns a base64-encoded string.
     *
     * Output format: base64( nonce[24] || ciphertext[N + 16] )
     * The 16-byte MAC (Poly1305 authentication tag) is automatically appended
     * to the ciphertext by libsodium.
     *
     * @param string $plaintext Arbitrary data to encrypt (JSON, binary, text).
     * @return string           Base64-encoded encrypted blob, safe to send over HTTP.
     *
     * @throws SecurityException If encryption fails.
     */
    public function encrypt(string $plaintext): string
    {
        // Generate a cryptographically random nonce for this message.
        // A fresh nonce per message is critical — reusing a nonce with the same
        // key completely breaks the security of XSalsa20.
        $nonce = random_bytes(self::NONCE_BYTES);

        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        // Prepend the nonce to the ciphertext so the receiver can extract it.
        return base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypts a base64-encoded payload produced by `encrypt()`.
     *
     * @param  string $encoded Base64-encoded encrypted blob.
     * @param  string $url     Original URL, used for the SecurityException message.
     * @return string          Decrypted plaintext.
     *
     * @throws SecurityException If the ciphertext has been tampered with,
     *                           if the MAC fails, or if decoding fails.
     */
    public function decrypt(string $encoded, string $url = ''): string
    {
        $raw = base64_decode($encoded, strict: true);

        if ($raw === false || strlen($raw) <= self::NONCE_BYTES) {
            throw SecurityException::decryptionFailed($url);
        }

        // Split the wire format back into nonce and ciphertext.
        $nonce      = substr($raw, 0, self::NONCE_BYTES);
        $ciphertext = substr($raw, self::NONCE_BYTES);

        // sodium_crypto_secretbox_open returns false if the MAC check fails.
        // This is the tamper-detection mechanism: any bit-flip in the ciphertext
        // causes the Poly1305 MAC to mismatch, and decryption is refused.
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext === false) {
            throw SecurityException::decryptionFailed($url);
        }

        // Zero out the sensitive data from memory once we have the plaintext.
        sodium_memzero($nonce);

        return $plaintext;
    }

    /**
     * Wipes the derived key from memory when this object is destroyed.
     * Prevents the key from lingering in process memory after use.
     */
    public function __destruct()
    {
        sodium_memzero($this->key);
    }
}
