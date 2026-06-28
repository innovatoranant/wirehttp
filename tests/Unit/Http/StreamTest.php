<?php

declare(strict_types=1);

namespace WireHttp\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use WireHttp\Http\Stream;

final class StreamTest extends TestCase
{
    // ─── Creation ─────────────────────────────────────────────────────────────

    public function test_from_string_creates_readable_stream(): void
    {
        $stream = Stream::fromString('Hello, WireHTTP!');

        $this->assertTrue($stream->isReadable());
        $this->assertTrue($stream->isSeekable());
        $this->assertSame(16, $stream->getSize());
    }

    public function test_empty_stream_has_zero_size(): void
    {
        $stream = Stream::empty();

        $this->assertSame(0, $stream->getSize());
        $this->assertSame('', $stream->getContents());
    }

    public function test_from_string_contents(): void
    {
        $stream = Stream::fromString('test content');

        $this->assertSame('test content', $stream->getContents());
    }

    // ─── Reading ─────────────────────────────────────────────────────────────

    public function test_read_returns_chunk(): void
    {
        $stream = Stream::fromString('Hello World');

        $chunk = $stream->read(5);

        $this->assertSame('Hello', $chunk);
    }

    public function test_read_advances_position(): void
    {
        $stream = Stream::fromString('Hello World');

        $stream->read(5);
        $chunk = $stream->read(6);

        $this->assertSame(' World', $chunk);
    }

    public function test_eof_after_reading_all(): void
    {
        $stream = Stream::fromString('small');
        $stream->getContents();

        $this->assertTrue($stream->eof());
    }

    public function test_get_contents_returns_remaining(): void
    {
        $stream = Stream::fromString('Hello World');
        $stream->read(6); // Read "Hello "

        $this->assertSame('World', $stream->getContents());
    }

    // ─── Seeking ─────────────────────────────────────────────────────────────

    public function test_rewind_resets_position(): void
    {
        $stream = Stream::fromString('Hello');
        $stream->getContents();

        $stream->rewind();

        $this->assertSame('Hello', $stream->getContents());
    }

    public function test_seek_to_position(): void
    {
        $stream = Stream::fromString('Hello World');
        $stream->seek(6);

        $this->assertSame('World', $stream->getContents());
    }

    public function test_tell_returns_current_position(): void
    {
        $stream = Stream::fromString('Hello World');
        $stream->read(5);

        $this->assertSame(5, $stream->tell());
    }

    // ─── Writing ─────────────────────────────────────────────────────────────

    public function test_write_appends_data(): void
    {
        $stream = Stream::empty();
        $stream->write('Hello');
        $stream->write(', World');

        $stream->rewind();

        $this->assertSame('Hello, World', $stream->getContents());
    }

    public function test_write_returns_bytes_written(): void
    {
        $stream    = Stream::empty();
        $bytesWritten = $stream->write('Hello');

        $this->assertSame(5, $bytesWritten);
    }

    // ─── Size ─────────────────────────────────────────────────────────────────

    public function test_size_reflects_written_data(): void
    {
        $stream = Stream::empty();
        $stream->write('12345');

        $this->assertSame(5, $stream->getSize());
    }

    public function test_size_of_string_stream(): void
    {
        $payload = 'The quick brown fox';
        $stream  = Stream::fromString($payload);

        $this->assertSame(strlen($payload), $stream->getSize());
    }

    // ─── Close & Detach ───────────────────────────────────────────────────────

    public function test_close_makes_stream_unreadable(): void
    {
        $stream = Stream::fromString('data');
        $stream->close();

        $this->assertFalse($stream->isReadable());
    }

    public function test_detach_returns_resource(): void
    {
        $stream   = Stream::fromString('data');
        $resource = $stream->detach();

        $this->assertIsResource($resource);
    }

    public function test_detach_makes_stream_unusable(): void
    {
        $stream = Stream::fromString('data');
        $stream->detach();

        $this->assertFalse($stream->isReadable());
        $this->assertNull($stream->getSize());
    }

    // ─── String Cast ─────────────────────────────────────────────────────────

    public function test_to_string_returns_full_content(): void
    {
        $stream = Stream::fromString('Cast me to string');
        $stream->read(5); // Advance position

        // __toString should rewind and return all content
        $this->assertSame('Cast me to string', (string) $stream);
    }

    // ─── Large Data ───────────────────────────────────────────────────────────

    public function test_handles_large_binary_content(): void
    {
        $data   = random_bytes(1024 * 512); // 512 KB
        $stream = Stream::fromString($data);

        $this->assertSame(1024 * 512, $stream->getSize());

        $readBack = $stream->getContents();
        $this->assertSame($data, $readBack);
    }
}
