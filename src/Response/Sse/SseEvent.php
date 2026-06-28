<?php

declare(strict_types=1);

namespace WireHttp\Response\Sse;

/**
 * SseEvent — An Immutable Server-Sent Event Chunk
 *
 * Represents a single parsed event from a Server-Sent Events (SSE) stream.
 * SSE streams are line-based text protocols defined by the W3C WHATWG spec:
 * https://html.spec.whatwg.org/multipage/server-sent-events.html
 *
 * A raw SSE event in the stream looks like this:
 *
 *   id: 42
 *   event: user_joined
 *   data: {"id":7,"name":"Alice"}
 *   retry: 3000
 *
 * Which maps to:
 *   SseEvent->id    = "42"
 *   SseEvent->type  = "user_joined"
 *   SseEvent->data  = '{"id":7,"name":"Alice"}'
 *   SseEvent->retry = 3000
 *
 * Multi-line data:
 * ----------------
 * The SSE spec allows multiple `data:` lines, which are joined with a newline:
 *
 *   data: line one
 *   data: line two
 *
 * → data = "line one\nline two"
 *
 * Ping/heartbeat events:
 * ----------------------
 * Many SSE servers send `: comment` lines as keepalives. These are parsed
 * into `SseEvent` objects with `$isComment = true` and `$data = comment text`.
 * They are typically filtered out by the consumer.
 */
final class SseEvent
{
    public function __construct(
        /** The event data payload (multi-line data joined with \n). */
        public readonly string  $data = '',

        /** The event type. Defaults to "message" per the SSE spec. */
        public readonly string  $type = 'message',

        /** The event ID (from the `id:` field). */
        public readonly ?string $id = null,

        /** Retry reconnection time in milliseconds (from the `retry:` field). */
        public readonly ?int    $retry = null,

        /** True if this event is a comment/heartbeat line (`: ...`). */
        public readonly bool    $isComment = false,
    ) {
    }

    /**
     * Attempts to JSON-decode the event data.
     *
     * @return array<string, mixed>|null  Null if data is not valid JSON.
     */
    public function json(): ?array
    {
        if ($this->data === '') {
            return null;
        }

        try {
            $decoded = json_decode($this->data, associative: true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Returns true if this event carries any actual data (not a comment/ping).
     */
    public function hasData(): bool
    {
        return !$this->isComment && $this->data !== '';
    }

    /**
     * Returns a debug representation of this event.
     */
    public function __toString(): string
    {
        $parts = [];

        if ($this->id !== null) {
            $parts[] = "id: {$this->id}";
        }

        if ($this->type !== 'message') {
            $parts[] = "event: {$this->type}";
        }

        if ($this->data !== '') {
            foreach (explode("\n", $this->data) as $line) {
                $parts[] = "data: {$line}";
            }
        }

        if ($this->retry !== null) {
            $parts[] = "retry: {$this->retry}";
        }

        return implode("\n", $parts);
    }
}
