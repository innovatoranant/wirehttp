<?php

declare(strict_types=1);

namespace WireHttp\Enums;

/**
 * Authentication Type Enum
 *
 * Represents all authentication schemes natively supported by WireHTTP.
 * Each case maps to both a string label and the corresponding cURL constant
 * (where applicable) so the transport layer can apply them without any
 * conditional string-matching logic scattered in the codebase.
 */
enum AuthType: string
{
    /**
     * HTTP Basic Authentication (RFC 7617)
     * Credentials are base64-encoded and sent in the Authorization header.
     * ONLY use over HTTPS — base64 is not encryption!
     *
     * Header format: `Authorization: Basic <base64(username:password)>`
     */
    case BASIC = 'basic';

    /**
     * HTTP Digest Authentication (RFC 7616)
     * A challenge-response scheme. The server issues a nonce, the client hashes
     * the credentials with it. More secure than Basic but still largely replaced
     * by Bearer tokens in modern APIs.
     *
     * Header format: `Authorization: Digest username="...", realm="...", ...`
     */
    case DIGEST = 'digest';

    /**
     * Bearer Token Authentication (RFC 6750 — OAuth 2.0)
     * A token (JWT, opaque, etc.) is passed directly in the Authorization header.
     * The most common authentication method in modern REST APIs.
     *
     * Header format: `Authorization: Bearer <token>`
     */
    case BEARER = 'bearer';

    /**
     * NTLM Authentication (Microsoft proprietary)
     * NT LAN Manager challenge-response scheme used for authenticating against
     * Windows servers (IIS, SharePoint, etc.). Requires cURL's NTLM support.
     *
     * Header format: Handled automatically by cURL via multi-step challenge.
     */
    case NTLM = 'ntlm';

    /**
     * Negotiate / Kerberos Authentication (SPNEGO, RFC 4559)
     * Single Sign-On (SSO) scheme used in enterprise environments.
     * Negotiates between Kerberos and NTLM based on server capability.
     * Requires cURL with GSS-API or SSPI support.
     *
     * Header format: `Authorization: Negotiate <SPNEGO-token>`
     */
    case NEGOTIATE = 'negotiate';

    /**
     * AWS Signature Version 4 Authentication (Amazon Web Services)
     * A custom HMAC-SHA256 signing scheme used for authenticating requests
     * to AWS services (S3, EC2, API Gateway, etc.).
     * WireHTTP's `HmacSigner` implements this natively.
     */
    case AWS_SIG_V4 = 'aws_sig_v4';

    /**
     * JSON Web Token — typically used as a Bearer token but explicitly typed
     * here to allow WireHTTP to handle JWT signing/refresh internally.
     */
    case JWT = 'jwt';

    /**
     * API Key authentication passed as a header.
     * The key name and value are supplied by the developer.
     *
     * Header format: `X-Api-Key: <key>` (or any custom header name)
     */
    case API_KEY = 'api_key';

    /**
     * OAuth 1.0a — Signed requests using a consumer key/secret and token/secret.
     * Used by legacy APIs (Twitter v1, some Magento APIs etc.).
     * WireHTTP's `HmacSigner` can produce OAuth 1.0a signatures natively.
     */
    case OAUTH1 = 'oauth1';

    // ─── Utility Methods ─────────────────────────────────────────────────────

    /**
     * Returns the corresponding cURL CURLAUTH_* constant for cURL-native auth types.
     * Returns null for auth types that are handled at the WireHTTP layer (not cURL).
     *
     * @return int|null The cURL constant or null if not applicable.
     */
    public function toCurlAuth(): ?int
    {
        return match ($this) {
            self::BASIC      => CURLAUTH_BASIC,
            self::DIGEST     => CURLAUTH_DIGEST,
            self::NTLM       => CURLAUTH_NTLM,
            self::NEGOTIATE  => CURLAUTH_NEGOTIATE,
            // These are handled by WireHTTP middleware/headers, not cURL:
            self::BEARER, self::AWS_SIG_V4, self::JWT,
            self::API_KEY, self::OAUTH1 => null,
        };
    }

    /**
     * Returns true if this authentication type is handled natively by cURL.
     * Returns false if WireHTTP must construct and inject the header manually.
     */
    public function isCurlNative(): bool
    {
        return $this->toCurlAuth() !== null;
    }

    /**
     * Returns true if this auth type transmits credentials in plaintext (or near-plaintext)
     * and therefore MUST only be used over HTTPS connections.
     */
    public function requiresHttps(): bool
    {
        return match ($this) {
            self::BASIC, self::API_KEY => true,
            default => false,
        };
    }

    /**
     * Returns the Authorization header prefix string for header-based auth types.
     * Returns null if the auth type does not use a standard Authorization header prefix.
     */
    public function headerPrefix(): ?string
    {
        return match ($this) {
            self::BASIC     => 'Basic',
            self::DIGEST    => 'Digest',
            self::BEARER    => 'Bearer',
            self::JWT       => 'Bearer',
            self::NEGOTIATE => 'Negotiate',
            // These have no standard prefix or are multi-header schemes:
            self::NTLM, self::AWS_SIG_V4,
            self::OAUTH1, self::API_KEY => null,
        };
    }
}
