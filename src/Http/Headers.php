<?php

declare(strict_types=1);

namespace WireHttp\Http;

/**
 * Headers — Ultra-Fast, RFC 7230-Compliant HTTP Header Store
 *
 * This class is the single most performance-critical data structure in WireHTTP.
 * Every request and response carries headers through every layer of the stack,
 * so we optimize aggressively here.
 *
 * Internal Storage Strategy:
 * ---------------------------
 * We use a dual-index structure:
 *   - `$normalized`: array<lowercase-name => list<string>>
 *     The primary lookup table. Keys are always lowercase for O(1) case-insensitive lookups.
 *     Values are arrays of header value strings (one header can appear multiple times).
 *   - `$originalCase`: array<lowercase-name => string>
 *     Stores the original casing of the header name as first seen.
 *     Used when serializing the request to preserve developer-set casing (e.g., "Content-Type").
 *
 * RFC Compliance:
 * ---------------
 *  - RFC 7230 §3.2: Header fields are case-insensitive.
 *  - RFC 7230 §3.2.6: Header field values can contain any visible US-ASCII characters,
 *    plus spaces and tabs. We validate and strip invalid characters.
 *  - RFC 7230 §3.2.2: Multiple identical header fields may be present. Values from
 *    duplicate headers CAN be combined with a comma (", ") for most headers but NOT
 *    for Set-Cookie (RFC 6265 §4.1). We store them as separate array entries.
 *
 * Performance Characteristics:
 * -----------------------------
 *  - `has()`:  O(1) — direct key lookup on the normalized array.
 *  - `get()`:  O(1) — direct key lookup, returns the pre-stored array.
 *  - `getLine()`: O(n) where n = number of values for that header — join operation.
 *  - `set()`:  O(1) — direct array write.
 *  - `add()`:  O(1) — array_push equivalent.
 *
 * This is an immutable-by-design value object. All mutation methods return a
 * new cloned instance, making it safe to share across async Fiber contexts.
 */
final class Headers
{
    /**
     * Primary lookup array: lowercase name → array of values.
     * Never access this directly from outside — always use the public API.
     *
     * @var array<string, list<string>>
     */
    private array $normalized = [];

    /**
     * Original-case mapping: lowercase name → original-case header name.
     * Used for serialization so we preserve what the developer typed.
     *
     * @var array<string, string>
     */
    private array $originalCase = [];

    /**
     * Constructs a Headers instance from a raw array.
     *
     * The input array may use any of these formats:
     *   - string keys: ['Content-Type' => 'application/json']
     *   - multi-value: ['Accept' => ['text/html', 'application/json']]
     *
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(array $headers = [])
    {
        foreach ($headers as $name => $values) {
            $this->writeHeader((string) $name, (array) $values);
        }
    }

    /**
     * Returns true if a header with the given name exists (case-insensitive).
     */
    public function has(string $name): bool
    {
        return isset($this->normalized[strtolower($name)]);
    }

    /**
     * Returns all values for a given header as an array.
     * Returns an empty array if the header does not exist.
     *
     * @return list<string>
     */
    public function get(string $name): array
    {
        return $this->normalized[strtolower($name)] ?? [];
    }

    /**
     * Returns all header values for a given name joined with ", ".
     * This is the HTTP wire format for combining multiple header values.
     * Returns an empty string if the header does not exist.
     */
    public function getLine(string $name): string
    {
        $values = $this->get($name);

        return empty($values) ? '' : implode(', ', $values);
    }

    /**
     * Returns the original-case header name as first provided to this object.
     * Falls back to the input name if we have never seen this header.
     */
    public function getOriginalName(string $name): string
    {
        return $this->originalCase[strtolower($name)] ?? $name;
    }

    /**
     * Returns all headers as an array with original-case keys.
     *
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        $result = [];

        foreach ($this->normalized as $lower => $values) {
            $originalName          = $this->originalCase[$lower] ?? $lower;
            $result[$originalName] = $values;
        }

        return $result;
    }

    /**
     * Returns all lowercase header names currently stored.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->normalized);
    }

    /**
     * Returns a new Headers instance with the given header SET (overwriting any existing values).
     *
     * @param string|list<string> $values
     */
    public function set(string $name, string|array $values): static
    {
        $clone = clone $this;
        $clone->writeHeader($name, (array) $values, overwrite: true);

        return $clone;
    }

    /**
     * Returns a new Headers instance with the given value(s) APPENDED to the header.
     * If the header does not exist yet, it is created.
     *
     * @param string|list<string> $values
     */
    public function add(string $name, string|array $values): static
    {
        $clone = clone $this;
        $clone->writeHeader($name, (array) $values, overwrite: false);

        return $clone;
    }

    /**
     * Returns a new Headers instance with the given header removed.
     */
    public function remove(string $name): static
    {
        $lower = strtolower($name);

        if (!isset($this->normalized[$lower])) {
            return $this;
        }

        $clone = clone $this;
        unset($clone->normalized[$lower], $clone->originalCase[$lower]);

        return $clone;
    }

    /**
     * Returns a new Headers instance merging the given headers into this one.
     * Headers from $other will overwrite headers with the same name in this instance.
     *
     * @param array<string, string|list<string>>|Headers $other
     */
    public function merge(array|Headers $other): static
    {
        $clone = clone $this;

        $items = $other instanceof Headers ? $other->all() : $other;

        foreach ($items as $name => $values) {
            $clone->writeHeader((string) $name, (array) $values, overwrite: true);
        }

        return $clone;
    }

    /**
     * Returns true if there are no headers stored.
     */
    public function isEmpty(): bool
    {
        return empty($this->normalized);
    }

    /**
     * Returns the number of distinct header names stored.
     */
    public function count(): int
    {
        return count($this->normalized);
    }

    /**
     * Serializes all headers into the HTTP wire format.
     * Each header name/value pair becomes one line in "Name: value\r\n" format.
     * Multiple values for the same header produce multiple lines
     * (the safest approach for Set-Cookie and similar headers).
     */
    public function toWireFormat(): string
    {
        $lines = [];

        foreach ($this->normalized as $lower => $values) {
            $name = $this->originalCase[$lower] ?? $lower;

            foreach ($values as $value) {
                $lines[] = $name . ': ' . $value;
            }
        }

        return implode("\r\n", $lines);
    }

    /**
     * Validates and normalizes a header name per RFC 7230.
     * Header names must consist of visible ASCII token characters.
     *
     * @throws \InvalidArgumentException if the name contains invalid characters.
     */
    public static function validateName(string $name): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Header name cannot be empty.');
        }

        // RFC 7230 token: any VCHAR except delimiters
        if (!preg_match('/^[!#$%&\'*+\-.^_`|~0-9a-zA-Z]+$/', $name)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid header name "%s". Header names must be RFC 7230 tokens ' .
                    '(visible ASCII excluding delimiters).',
                    $name
                )
            );
        }
    }

    /**
     * Validates a header value per RFC 7230.
     * Values must be printable US-ASCII characters, spaces, and tabs.
     * Leading/trailing whitespace is stripped (header folding is obsoleted in RFC 7230).
     *
     * @throws \InvalidArgumentException if the value contains invalid characters.
     */
    public static function validateValue(string $value): string
    {
        // Strip leading/trailing optional whitespace (OWS) per RFC 7230 §3.2.3
        $trimmed = trim($value, " \t");

        // RFC 7230: field-value = *( field-content / obs-fold )
        // obs-fold is deprecated. Valid chars: 0x09 (tab), 0x20-0x7E, 0x80-0xFF (obs-text)
        if (preg_match('/[^\x09\x20-\x7e\x80-\xff]/', $trimmed)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Invalid header value. Values must contain only printable ASCII and extended bytes, ' .
                    'got control character in: "%s".',
                    addcslashes($trimmed, "\0..\37\177")
                )
            );
        }

        return $trimmed;
    }

    /**
     * Internal write method. Handles both overwrite (set) and append (add) modes.
     *
     * @param list<string> $values
     */
    private function writeHeader(string $name, array $values, bool $overwrite = true): void
    {
        static::validateName($name);

        $lower = strtolower($name);

        // Validate and trim all values
        $cleanValues = array_map(static::validateValue(...), $values);

        if ($overwrite || !isset($this->normalized[$lower])) {
            $this->normalized[$lower]  = $cleanValues;
            $this->originalCase[$lower] = $name;
        } else {
            // Append mode: push new values onto existing array
            array_push($this->normalized[$lower], ...$cleanValues);
        }
    }
}
