<?php

declare(strict_types=1);

namespace WireHttp\Response\Sse;

use WireHttp\Exceptions\StreamException;
use WireHttp\Http\Response;
use WireHttp\Http\Stream;

/**
 * SseParser — Streaming Server-Sent Events Parser
 *
 * Parses the body of an HTTP response that uses the `text/event-stream`
 * content type (Server-Sent Events) into a sequence of `SseEvent` objects.
 *
 * The parser works in two modes:
 *
 * 1. **Generator / Streaming mode** (`parse()`):
 *    Reads the stream chunk by chunk and yields `SseEvent` objects as they
 *    complete. This is the correct mode for long-running SSE connections
 *    (e.g., ChatGPT streaming, real-time notifications) because the parser
 *    processes each event the instant it's available, without buffering the
 *    entire response body.
 *
 * 2. **Collect mode** (`parseAll()`):
 *    Collects all yielded events into an array. Use only when the SSE
 *    response is bounded (e.g., a finite list of events with a terminal marker).
 *
 * SSE Protocol Rules (WHATWG spec):
 * -----------------------------------
 *  - Events are separated by blank lines.
 *  - Field names: `id`, `event`, `data`, `retry`, or `:` (comment).
 *  - Whitespace after the colon is stripped.
 *  - `data:` lines accumulate; multiple `data:` lines are joined with `\n`.
 *  - The `retry:` field must be a decimal integer (ignoring non-numeric values).
 *  - An event without a `data` field is NOT dispatched (dropped).
 *  - The last event ID is retained across events in the same stream.
 *
 * Usage — Streaming (Fiber/Generator):
 *   foreach ($parser->parse($response) as $event) {
 *       if ($event->type === 'user_joined') {
 *           echo $event->json()['name'];
 *       }
 *   }
 *
 * Usage — Collect all:
 *   $events = $parser->parseAll($response);
 *
 * Usage — OpenAI-style streaming (data: [DONE] terminator):
 *   $parser = new SseParser(terminateOn: '[DONE]');
 *   foreach ($parser->parse($response) as $event) {
 *       echo $event->json()['choices'][0]['delta']['content'] ?? '';
 *   }
 */
final class SseParser
{
    /**
     * Chunk size for reading from the stream in bytes.
     */
    private const CHUNK_SIZE = 4096;

    /**
     * Optional data value that signals end-of-stream (e.g., "[DONE]" for OpenAI).
     * When a `data` field exactly matches this string, the parser stops.
     */
    private readonly ?string $terminateOn;

    /**
     * Whether to yield comment/heartbeat events or silently skip them.
     */
    private readonly bool $yieldComments;

    public function __construct(
        ?string $terminateOn = null,
        bool    $yieldComments = false,
    ) {
        $this->terminateOn  = $terminateOn;
        $this->yieldComments = $yieldComments;
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Parses an SSE stream from an HTTP Response, yielding events as they complete.
     *
     * This is a Generator function. Consumption drives parsing — no work is done
     * until the consumer iterates (lazy evaluation).
     *
     * @param Response $response The SSE response to parse.
     * @return \Generator<int, SseEvent, null, void>
     * @throws StreamException If the response body is not a readable stream.
     */
    public function parse(Response $response): \Generator
    {
        $this->assertSseResponse($response);

        $stream  = $response->getBody();
        $buffer  = '';
        $lastId  = null;

        if (!$stream->isReadable()) {
            throw new StreamException('SSE response body stream is not readable.');
        }

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        // Read the stream in chunks
        while (!$stream->eof()) {
            $chunk = $stream->read(self::CHUNK_SIZE);

            if ($chunk === '' || $chunk === false) {
                continue;
            }

            $buffer .= $chunk;

            // SSE events are separated by blank lines (\n\n or \r\n\r\n)
            // We process as many complete events as are in the buffer
            while (true) {
                // Find the next event boundary
                $pos = $this->findEventBoundary($buffer);

                if ($pos === null) {
                    break; // No complete event yet — wait for more data
                }

                $eventBlock = substr($buffer, 0, $pos['end']);
                $buffer     = substr($buffer, $pos['end']);

                $event = $this->parseEventBlock($eventBlock, $lastId);

                if ($event === null) {
                    continue; // Empty or comment-only block
                }

                // Track the last event ID across events in the stream
                if ($event->id !== null) {
                    $lastId = $event->id;
                }

                // Handle termination sentinel
                if ($this->terminateOn !== null && $event->data === $this->terminateOn) {
                    return;
                }

                // Skip comments unless requested
                if ($event->isComment && !$this->yieldComments) {
                    continue;
                }

                // Only yield events with actual data (spec requirement)
                if ($event->hasData() || ($event->isComment && $this->yieldComments)) {
                    yield $event;
                }
            }
        }

        // Process any remaining data in the buffer (stream ended without trailing newline)
        if (trim($buffer) !== '') {
            $event = $this->parseEventBlock($buffer, $lastId);

            if ($event !== null && ($event->hasData() || ($event->isComment && $this->yieldComments))) {
                yield $event;
            }
        }
    }

    /**
     * Collects all SSE events into an array (not for long-running streams).
     *
     * @param Response $response
     * @return list<SseEvent>
     */
    public function parseAll(Response $response): array
    {
        return iterator_to_array($this->parse($response), preserve_keys: false);
    }

    /**
     * Creates a parser pre-configured for OpenAI-style streaming responses.
     * These use `data: [DONE]` as the end-of-stream terminator.
     */
    public static function openAi(): static
    {
        return new static(terminateOn: '[DONE]');
    }

    /**
     * Creates a parser that yields all events including heartbeat comments.
     */
    public static function withComments(): static
    {
        return new static(yieldComments: true);
    }

    // ─── Private: Parsing Logic ────────────────────────────────────────────────

    /**
     * Finds the position of the next event boundary (blank line) in the buffer.
     * Returns an array with 'start' and 'end' positions, or null if not found.
     *
     * @return array{start: int, end: int}|null
     */
    private function findEventBoundary(string $buffer): ?array
    {
        // Look for \n\n or \r\n\r\n (blank line separating events)
        $patterns = ["\r\n\r\n", "\n\n", "\r\r"];

        $found = null;

        foreach ($patterns as $pattern) {
            $pos = strpos($buffer, $pattern);

            if ($pos !== false) {
                if ($found === null || $pos < $found['start']) {
                    $found = ['start' => $pos, 'end' => $pos + strlen($pattern)];
                }
            }
        }

        return $found;
    }

    /**
     * Parses a single SSE event block (the text between two blank lines)
     * into an `SseEvent` object.
     *
     * Returns null if the block has no meaningful content.
     *
     * @param string  $block  The raw event text block.
     * @param ?string $lastId The last seen event ID (for ID inheritance).
     */
    private function parseEventBlock(string $block, ?string $lastId): ?SseEvent
    {
        $block = trim($block);

        if ($block === '') {
            return null;
        }

        $lines    = preg_split('/\r\n|\n|\r/', $block);
        $dataLines = [];
        $id        = $lastId;
        $type      = 'message';
        $retry     = null;
        $isComment = false;
        $comment   = '';

        foreach ($lines as $line) {
            $line = (string) $line;

            if ($line === '') {
                continue;
            }

            // Comment line: ": text"
            if (str_starts_with($line, ':')) {
                $isComment = true;
                $comment   = ltrim(substr($line, 1));

                continue;
            }

            // Field line: "field: value" or "field:"
            if (str_contains($line, ':')) {
                [$fieldName, $fieldValue] = explode(':', $line, 2);
                // Strip exactly ONE leading space from the value (per spec)
                $fieldValue = ltrim($fieldValue, ' ');
            } else {
                // Line is a field name with no colon → empty value (per spec)
                $fieldName  = $line;
                $fieldValue = '';
            }

            match ($fieldName) {
                'data'  => $dataLines[] = $fieldValue,
                'id'    => $id          = $fieldValue !== '' ? $fieldValue : null,
                'event' => $type        = $fieldValue ?: 'message',
                'retry' => $retry       = ctype_digit($fieldValue) ? (int) $fieldValue : $retry,
                default => null, // Unknown fields are ignored per spec
            };
        }

        // If this was only a comment line
        if ($isComment && empty($dataLines)) {
            return new SseEvent(
                data: $comment,
                type: 'comment',
                id: $id,
                isComment: true,
            );
        }

        // No data field = do not dispatch (per WHATWG spec)
        if (empty($dataLines)) {
            return null;
        }

        return new SseEvent(
            data: implode("\n", $dataLines),
            type: $type,
            id: $id,
            retry: $retry,
            isComment: false,
        );
    }

    // ─── Private: Validation ─────────────────────────────────────────────────

    private function assertSseResponse(Response $response): void
    {
        $contentType = $response->getHeaderLine('Content-Type');

        if ($contentType !== '' && !str_contains($contentType, 'text/event-stream')) {
            // We warn but don't throw — the server MIGHT be wrong about its content-type
            // and the body could still be a valid SSE stream (common in proxies).
            trigger_error(
                sprintf(
                    'SseParser: Expected "text/event-stream" Content-Type, got "%s". ' .
                    'Parsing anyway — verify server configuration.',
                    $contentType
                ),
                E_USER_WARNING
            );
        }
    }
}
