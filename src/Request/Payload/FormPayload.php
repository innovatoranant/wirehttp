<?php

declare(strict_types=1);

namespace WireHttp\Request\Payload;

use WireHttp\Http\Stream;

/**
 * FormPayload — Encodes Data as application/x-www-form-urlencoded
 *
 * Produces the classic HTML form submission body format.
 * Handles nested arrays using bracket notation (field[key]=value),
 * compatible with PHP's `parse_str()` and most web frameworks.
 *
 * Usage:
 *   $builder->withForm([
 *       'username' => 'alice',
 *       'filters'  => ['status' => 'active', 'role' => 'admin'],
 *   ]);
 *   // Produces: username=alice&filters%5Bstatus%5D=active&filters%5Brole%5D=admin
 */
final class FormPayload
{
    /**
     * Encodes the given associative array as a URL-encoded form body.
     *
     * @param array<string, mixed> $data   Form fields. Supports nested arrays.
     *
     * @return array{0: Stream, 1: string, 2: int}  [body stream, content-type, content-length]
     */
    public static function encode(array $data): array
    {
        $encoded = self::buildQuery($data);

        return [
            Stream::fromString($encoded),
            'application/x-www-form-urlencoded',
            strlen($encoded),
        ];
    }

    /**
     * Recursively builds a URL-encoded query string from nested arrays.
     * Uses PHP bracket notation for nested keys (field[subkey]=value).
     *
     * @param array<string, mixed> $data
     * @param string               $prefix
     */
    private static function buildQuery(array $data, string $prefix = ''): string
    {
        $parts = [];

        foreach ($data as $key => $value) {
            $fullKey = $prefix !== '' ? "{$prefix}[{$key}]" : (string) $key;

            if (is_array($value)) {
                $parts[] = self::buildQuery($value, $fullKey);
            } else {
                $parts[] = urlencode($fullKey) . '=' . urlencode((string) $value);
            }
        }

        return implode('&', array_filter($parts));
    }
}
