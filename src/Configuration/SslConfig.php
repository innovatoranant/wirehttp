<?php

declare(strict_types=1);

namespace WireHttp\Configuration;

/**
 * SslConfig — TLS/SSL Certificate and Verification Settings DTO
 *
 * Controls every aspect of HTTPS certificate handling. The default
 * configuration is deliberately SECURE — verification is always enabled.
 * You must explicitly opt-out of security.
 *
 * Production vs. Development:
 * ---------------------------
 * Never disable SSL verification in production. Ever.
 * Use `SslConfig::insecure()` ONLY for local development against
 * self-signed certificates. Do not commit `insecure()` to version control.
 */
final class SslConfig
{
    public function __construct(
        /** Verify the server's SSL certificate against a CA bundle. Default: true. */
        public readonly bool    $verifyPeer = true,

        /** Verify that the server's hostname matches its SSL certificate. Default: true. */
        public readonly bool    $verifyHost = true,

        /** Path to a CA certificate bundle (.pem) for verifying the server's cert. */
        public readonly ?string $caBundle = null,

        /** Path to a directory of CA certificates for verification. */
        public readonly ?string $caPath = null,

        /** Path to the client SSL certificate file (.pem) for mutual TLS (mTLS). */
        public readonly ?string $clientCert = null,

        /** Path to the client SSL private key file for mutual TLS (mTLS). */
        public readonly ?string $clientKey = null,

        /**
         * Passphrase for the client private key, if the key is encrypted.
         * WARNING: Be careful with logging — this is a secret value.
         */
        public readonly ?string $clientKeyPassphrase = null,

        /**
         * Minimum TLS protocol version. Maps to CURL_SSLVERSION_* constants.
         * Recommended: 'TLSv1.2' or 'TLSv1.3'.
         * Values: 'TLSv1.0', 'TLSv1.1', 'TLSv1.2', 'TLSv1.3'
         */
        public readonly ?string $minVersion = 'TLSv1.2',

        /** Maximum TLS protocol version (usually not needed). */
        public readonly ?string $maxVersion = null,
    ) {
    }

    /**
     * INSECURE: Disables ALL SSL verification.
     *
     * WARNING: This makes your application vulnerable to MITM attacks.
     * Only for local dev/testing against self-signed certs.
     * NEVER use in production or commit to version control.
     */
    public static function insecure(): static
    {
        return new static(verifyPeer: false, verifyHost: false, minVersion: null);
    }

    /**
     * Creates an SslConfig using a custom CA bundle file.
     * Use when connecting to services with a private CA.
     */
    public static function withCaBundle(string $caBundle): static
    {
        return new static(caBundle: $caBundle);
    }

    /**
     * Creates an SslConfig for mutual TLS (mTLS) authentication.
     * The client presents a certificate to the server in addition to verifying the server.
     */
    public static function withClientCert(
        string  $certPath,
        string  $keyPath,
        ?string $passphrase = null,
    ): static {
        return new static(
            clientCert: $certPath,
            clientKey: $keyPath,
            clientKeyPassphrase: $passphrase,
        );
    }

    /**
     * TLS 1.3 ONLY — the most secure option. Supported by modern servers.
     */
    public static function tls13Only(): static
    {
        return new static(minVersion: 'TLSv1.3', maxVersion: 'TLSv1.3');
    }

    /**
     * Maps the version string to the corresponding CURLOPT_SSLVERSION value.
     *
     * @return int|null Returns null if no version restriction is set.
     */
    public function getCurlSslVersion(): ?int
    {
        if ($this->minVersion === null) {
            return null;
        }

        return match ($this->minVersion) {
            'TLSv1.0' => CURL_SSLVERSION_TLSv1_0,
            'TLSv1.1' => CURL_SSLVERSION_TLSv1_1,
            'TLSv1.2' => CURL_SSLVERSION_TLSv1_2,
            'TLSv1.3' => CURL_SSLVERSION_TLSv1_3,
            default   => null,
        };
    }
}
