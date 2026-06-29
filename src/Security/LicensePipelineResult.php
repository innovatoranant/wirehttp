<?php

declare(strict_types=1);

namespace WireHttp\Security;

use WireHttp\Security\Exception\SecurityException;

/**
 * LicensePipelineResult — Immutable result from the LicensePipeline.
 *
 * Returned by `LicensePipeline::send()`. Wraps the raw decrypted response
 * body and exposes ergonomic methods for the developer to consume the data.
 *
 * Example:
 *   $result = Wire::license('https://api.server.com/verify')
 *       ->withPayload(['key' => 'ABCD-1234'])
 *       ->send();
 *
 *   if ($result->isValid()) {
 *       echo $result->get('plan'); // 'pro'
 *   }
 *
 *   // Or access the full decoded array:
 *   $data = $result->json();
 */
final class LicensePipelineResult
{
    /** @var array<string, mixed>|null Lazily decoded JSON — null until json() is first called. */
    private ?array $decoded = null;

    /**
     * @param string $rawBody   The raw decrypted response body.
     * @param int    $statusCode HTTP status code of the response.
     */
    public function __construct(
        private readonly string $rawBody,
        private readonly int    $statusCode,
    ) {}

    /**
     * Returns true if the HTTP status code indicates success (200-299).
     */
    public function isValid(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Returns the HTTP status code of the license server's response.
     */
    public function status(): int
    {
        return $this->statusCode;
    }

    /**
     * Returns the fully decoded response array.
     * Lazily decodes the JSON body on first call.
     *
     * @return array<string, mixed>
     * @throws \JsonException If the response body is not valid JSON.
     */
    public function json(): array
    {
        if ($this->decoded === null) {
            $this->decoded = json_decode($this->rawBody, associative: true, flags: JSON_THROW_ON_ERROR);
        }

        return $this->decoded;
    }

    /**
     * Returns a specific field from the decoded response array.
     * Supports dot-notation for nested keys: e.g., 'license.expiry'
     *
     * @param string $key     Dot-notation key path.
     * @param mixed  $default Default value if the key doesn't exist.
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $parts  = explode('.', $key);
        // Use json() to trigger lazy decode
        $cursor = $this->json();

        foreach ($parts as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$part];
        }

        return $cursor;
    }

    /**
     * Returns the raw decrypted response body string.
     * Useful for custom parsing or logging (after security checks have passed).
     */
    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * Convenience: throws if not valid.
     *
     * @throws SecurityException If the response status is not 2xx.
     */
    public function throwIfInvalid(): self
    {
        if (!$this->isValid()) {
            throw new SecurityException(
                "License server rejected the request with HTTP {$this->statusCode}.",
                ['status' => $this->statusCode, 'body' => $this->rawBody],
            );
        }

        return $this;
    }
}
