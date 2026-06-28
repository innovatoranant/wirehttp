<?php

declare(strict_types=1);

namespace WireHttp\Request\Payload;

use WireHttp\Http\Stream;

/**
 * JsonPayload — Encodes Data as application/json
 *
 * Accepts any JSON-serializable PHP value and encodes it into a UTF-8 JSON string.
 * Sets `Content-Type: application/json; charset=utf-8` and `Content-Length`.
 *
 * Usage:
 *   $builder->withJson(['name' => 'Alice', 'email' => 'alice@example.com']);
 *   // Internally creates: JsonPayload::encode(['name' => 'Alice', ...])
 */
final class JsonPayload
{
    /** @var array<int, mixed> JSON encoding flags applied by default. */
    private const DEFAULT_FLAGS = JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Encodes the given data as JSON and returns [Stream, Content-Type, Content-Length].
     *
     * @param mixed     $data    Any JSON-serializable value.
     * @param int       $flags   json_encode() flags (merged with defaults).
     * @param int       $depth   Maximum nesting depth.
     *
     * @return array{0: Stream, 1: string, 2: int}  [body stream, content-type, content-length]
     * @throws \JsonException If the data cannot be serialized.
     */
    public static function encode(mixed $data, int $flags = 0, int $depth = 512): array
    {
        $json = json_encode($data, self::DEFAULT_FLAGS | $flags | JSON_THROW_ON_ERROR, $depth);

        return [
            Stream::fromString($json),
            'application/json; charset=utf-8',
            strlen($json),
        ];
    }
}
