<?php

declare(strict_types=1);

namespace WireHttp\Tests\Unit\Response;

use PHPUnit\Framework\TestCase;
use WireHttp\Http\Response;
use WireHttp\Http\Stream;
use WireHttp\Response\Sse\SseEvent;
use WireHttp\Response\Sse\SseParser;

final class SseParserTest extends TestCase
{
    private function sseResponse(string $body): Response
    {
        return new Response(200, [
            'Content-Type' => ['text/event-stream'],
        ], Stream::fromString($body));
    }

    // ─── Basic Parsing ────────────────────────────────────────────────────────

    public function test_parses_single_simple_event(): void
    {
        $body   = "data: Hello World\n\n";
        $events = (new SseParser())->parseAll($this->sseResponse($body));

        $this->assertCount(1, $events);
        $this->assertSame('Hello World', $events[0]->data);
        $this->assertSame('message', $events[0]->type); // Default type
    }

    public function test_parses_event_with_id_and_type(): void
    {
        $body = "id: 42\nevent: user_joined\ndata: {\"name\":\"Alice\"}\n\n";

        $events = (new SseParser())->parseAll($this->sseResponse($body));

        $this->assertCount(1, $events);
        $this->assertSame('42', $events[0]->id);
        $this->assertSame('user_joined', $events[0]->type);
        $this->assertSame('{"name":"Alice"}', $events[0]->data);
    }

    // ─── Multi-line Data ─────────────────────────────────────────────────────

    public function test_joins_multiple_data_lines_with_newline(): void
    {
        $body = "data: line one\ndata: line two\ndata: line three\n\n";

        $events = (new SseParser())->parseAll($this->sseResponse($body));

        $this->assertCount(1, $events);
        $this->assertSame("line one\nline two\nline three", $events[0]->data);
    }

    // ─── Multiple Events ─────────────────────────────────────────────────────

    public function test_parses_multiple_events(): void
    {
        $body = "data: first\n\ndata: second\n\ndata: third\n\n";

        $events = (new SseParser())->parseAll($this->sseResponse($body));

        $this->assertCount(3, $events);
        $this->assertSame('first', $events[0]->data);
        $this->assertSame('second', $events[1]->data);
        $this->assertSame('third', $events[2]->data);
    }

    // ─── Retry Field ─────────────────────────────────────────────────────────

    public function test_parses_retry_field(): void
    {
        $body = "retry: 3000\ndata: hello\n\n";

        $events = (new SseParser())->parseAll($this->sseResponse($body));

        $this->assertCount(1, $events);
        $this->assertSame(3000, $events[0]->retry);
    }

    public function test_ignores_non_numeric_retry(): void
    {
        $body = "retry: not-a-number\ndata: hello\n\n";

        $events = (new SseParser())->parseAll($this->sseResponse($body));

        $this->assertNull($events[0]->retry);
    }

    // ─── Comments / Heartbeats ────────────────────────────────────────────────

    public function test_skips_comments_by_default(): void
    {
        $body = ": heartbeat\n\ndata: real event\n\n";

        $events = (new SseParser())->parseAll($this->sseResponse($body));

        $this->assertCount(1, $events);
        $this->assertSame('real event', $events[0]->data);
    }

    public function test_yields_comments_when_configured(): void
    {
        $body   = ": keep-alive\n\ndata: real\n\n";
        $parser = SseParser::withComments();

        $events = $parser->parseAll($this->sseResponse($body));

        $this->assertCount(2, $events);
        $this->assertTrue($events[0]->isComment);
        $this->assertFalse($events[1]->isComment);
    }

    // ─── Events Without Data Are Dropped ─────────────────────────────────────

    public function test_drops_events_without_data_field(): void
    {
        $body = "id: 1\nevent: ping\n\ndata: actual\n\n";

        $events = (new SseParser())->parseAll($this->sseResponse($body));

        // First block (id+event, no data) is dropped per spec
        $this->assertCount(1, $events);
        $this->assertSame('actual', $events[0]->data);
    }

    // ─── JSON Helper ─────────────────────────────────────────────────────────

    public function test_sse_event_json_decodes_data(): void
    {
        $body   = 'data: {"id":1,"name":"Alice"}' . "\n\n";
        $events = (new SseParser())->parseAll($this->sseResponse($body));

        $json = $events[0]->json();

        $this->assertIsArray($json);
        $this->assertSame(1, $json['id']);
        $this->assertSame('Alice', $json['name']);
    }

    public function test_sse_event_json_returns_null_for_non_json(): void
    {
        $event = new SseEvent(data: 'plain text');

        $this->assertNull($event->json());
    }

    // ─── [DONE] Terminator ────────────────────────────────────────────────────

    public function test_openai_parser_stops_on_done_terminator(): void
    {
        $body = "data: chunk1\n\ndata: chunk2\n\ndata: [DONE]\n\ndata: after-done\n\n";

        $events = SseParser::openAi()->parseAll($this->sseResponse($body));

        // Should stop at [DONE] — "after-done" is never yielded
        $this->assertCount(2, $events);
        $this->assertSame('chunk1', $events[0]->data);
        $this->assertSame('chunk2', $events[1]->data);
    }

    // ─── CRLF Line Endings ────────────────────────────────────────────────────

    public function test_handles_crlf_line_endings(): void
    {
        $body = "data: Windows\r\n\r\n";

        $events = (new SseParser())->parseAll($this->sseResponse($body));

        $this->assertCount(1, $events);
        $this->assertSame('Windows', $events[0]->data);
    }

    // ─── Generator Mode ───────────────────────────────────────────────────────

    public function test_parse_returns_generator(): void
    {
        $body   = "data: one\n\ndata: two\n\n";
        $parser = new SseParser();

        $gen = $parser->parse($this->sseResponse($body));

        $this->assertInstanceOf(\Generator::class, $gen);

        $events = iterator_to_array($gen, preserve_keys: false);
        $this->assertCount(2, $events);
    }

    // ─── hasData Check ────────────────────────────────────────────────────────

    public function test_has_data_returns_true_for_events_with_data(): void
    {
        $event = new SseEvent(data: 'content');
        $this->assertTrue($event->hasData());
    }

    public function test_has_data_returns_false_for_comments(): void
    {
        $event = new SseEvent(data: 'ping', isComment: true);
        $this->assertFalse($event->hasData());
    }

    public function test_has_data_returns_false_for_empty_data(): void
    {
        $event = new SseEvent(data: '');
        $this->assertFalse($event->hasData());
    }
}
