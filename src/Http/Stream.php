<?php

declare(strict_types=1);

namespace WireHttp\Http;

/**
 * Stream — Memory-Safe, High-Performance HTTP Message Body
 *
 * A Stream wraps a PHP resource handle and provides a clean, controlled API
 * for reading from and writing to that resource. It is used for both request
 * bodies (what you send) and response bodies (what you receive).
 *
 * Design Goals:
 * -------------
 *  1. **Memory efficiency:** We never load the entire body into memory at once
 *     unless the developer explicitly calls `getContents()`. Large file uploads
 *     and large API responses can be streamed chunk-by-chunk.
 *  2. **Immutability awareness:** Once detached or closed, the stream becomes
 *     a "dead" object. Attempting to use it after that point throws a \RuntimeException.
 *  3. **Thread/Fiber safety:** The internal PHP resource is the source of truth.
 *     Clone semantics are intentionally NOT supported (cloning a resource is undefined).
 *
 * Supported Backing Sources:
 * ---------------------------
 *  - In-memory strings via `php://memory` (default for small bodies)
 *  - Temporary files via `php://temp` (PHP spills to disk when > 2MB by default)
 *  - Real file handles (file uploads, large downloads)
 *  - `php://stdin`, `php://stdout` for CLI-piped usage
 *
 * Usage:
 *   $stream = Stream::fromString('{"hello":"world"}');
 *   $stream = Stream::fromFile('/path/to/large-file.zip', 'rb');
 *   $stream = Stream::fromResource(fopen('php://temp', 'r+'));
 */
final class Stream
{
    /** @var resource|null */
    private mixed $resource;

    /**
     * Cached metadata array from stream_get_meta_data(). Populated lazily.
     * Invalidated when the resource is detached.
     *
     * @var array<string, mixed>|null
     */
    private ?array $metadata = null;

    /**
     * Cached stream size in bytes. -1 means "unknown".
     * We cache this because fstat() on large files can be expensive.
     */
    private int $cachedSize = -1;

    /**
     * @param resource $resource A valid, open PHP stream resource.
     * @throws \InvalidArgumentException if the given value is not a stream resource.
     */
    public function __construct(mixed $resource)
    {
        if (!is_resource($resource) || get_resource_type($resource) !== 'stream') {
            throw new \InvalidArgumentException(
                sprintf(
                    'WireHttp\Stream requires a valid stream resource, got "%s".',
                    is_resource($resource) ? get_resource_type($resource) : gettype($resource)
                )
            );
        }

        $this->resource = $resource;
    }

    // ─── Factory Methods ──────────────────────────────────────────────────────

    /**
     * Creates a Stream from a string value.
     * Uses a `php://memory` handle for strings ≤ 2MB (keeping them in RAM),
     * and `php://temp` (disk spill) for larger strings.
     */
    public static function fromString(string $content): static
    {
        $sizeInBytes = strlen($content);
        $target      = $sizeInBytes <= (2 * 1024 * 1024) ? 'php://memory' : 'php://temp';

        $resource = fopen($target, 'r+');

        if ($resource === false) {
            throw new \RuntimeException("Failed to open {$target} stream handle.");
        }

        fwrite($resource, $content);
        rewind($resource);

        $instance             = new static($resource);
        $instance->cachedSize = $sizeInBytes;

        return $instance;
    }

    /**
     * Creates an empty, writable Stream backed by `php://memory`.
     * Useful for building request bodies incrementally.
     */
    public static function empty(): static
    {
        $resource = fopen('php://memory', 'r+');

        if ($resource === false) {
            throw new \RuntimeException('Failed to open php://memory stream handle.');
        }

        $instance             = new static($resource);
        $instance->cachedSize = 0;

        return $instance;
    }

    /**
     * Creates a Stream backed by a real file on disk.
     *
     * @param string $path The absolute path to the file.
     * @param string $mode The fopen mode (e.g., 'rb', 'r+b', 'wb').
     * @throws \RuntimeException if the file cannot be opened.
     */
    public static function fromFile(string $path, string $mode = 'rb'): static
    {
        $resource = fopen($path, $mode);

        if ($resource === false) {
            throw new \RuntimeException(
                sprintf('Failed to open file stream: "%s" with mode "%s".', $path, $mode)
            );
        }

        return new static($resource);
    }

    /**
     * Creates a Stream from an already-open PHP resource handle.
     *
     * @param resource $resource
     */
    public static function fromResource(mixed $resource): static
    {
        return new static($resource);
    }

    // ─── Core Stream Operations ───────────────────────────────────────────────

    /**
     * Reads up to $length bytes from the stream at the current position.
     * Returns an empty string at end-of-file.
     *
     * @throws \RuntimeException if the stream is not readable or has been detached.
     */
    public function read(int $length): string
    {
        $this->assertReadable();

        if ($length <= 0) {
            return '';
        }

        $data = fread($this->resource, $length);

        if ($data === false) {
            throw new \RuntimeException('Failed to read from stream.');
        }

        return $data;
    }

    /**
     * Writes data to the stream at the current position.
     * Returns the number of bytes actually written.
     *
     * @throws \RuntimeException if the stream is not writable or has been detached.
     */
    public function write(string $data): int
    {
        $this->assertWritable();

        $written = fwrite($this->resource, $data);

        if ($written === false) {
            throw new \RuntimeException('Failed to write to stream.');
        }

        // Invalidate the cached size since we just wrote new data
        $this->cachedSize = -1;

        return $written;
    }

    /**
     * Returns all remaining data from the current position to the end of the stream.
     * This buffers the entire remaining content into a PHP string in memory.
     * For very large streams, consider using `read()` in a loop instead.
     *
     * @throws \RuntimeException if the stream is not readable.
     */
    public function getContents(): string
    {
        $this->assertReadable();

        $contents = stream_get_contents($this->resource);

        if ($contents === false) {
            throw new \RuntimeException('Failed to read stream contents.');
        }

        return $contents;
    }

    /**
     * Converts the entire stream to a string by rewinding and reading all contents.
     * Returns an empty string if the stream is not readable or has been detached.
     */
    public function __toString(): string
    {
        if ($this->resource === null || !$this->isReadable()) {
            return '';
        }

        try {
            if ($this->isSeekable()) {
                $this->rewind();
            }

            return $this->getContents();
        } catch (\RuntimeException) {
            return '';
        }
    }

    // ─── Seeking ─────────────────────────────────────────────────────────────

    /**
     * Seeks to an absolute byte offset in the stream.
     *
     * @param int $offset Byte offset from the beginning of the stream.
     * @throws \RuntimeException if the stream is not seekable.
     */
    public function seek(int $offset): void
    {
        $this->assertSeekable();

        if (fseek($this->resource, $offset, SEEK_SET) !== 0) {
            throw new \RuntimeException(
                sprintf('Failed to seek to offset %d in stream.', $offset)
            );
        }
    }

    /**
     * Rewinds the stream to position 0 (the beginning).
     *
     * @throws \RuntimeException if the stream is not seekable.
     */
    public function rewind(): void
    {
        $this->assertSeekable();

        if (!rewind($this->resource)) {
            throw new \RuntimeException('Failed to rewind stream.');
        }
    }

    /**
     * Returns the current byte offset (position) within the stream.
     *
     * @throws \RuntimeException if the stream has been detached or position cannot be determined.
     */
    public function tell(): int
    {
        $this->assertAttached();

        $position = ftell($this->resource);

        if ($position === false) {
            throw new \RuntimeException('Failed to determine the current position in stream.');
        }

        return $position;
    }

    /**
     * Returns true if the stream position is at the end of the file.
     */
    public function eof(): bool
    {
        return $this->resource === null || feof($this->resource);
    }

    // ─── Capability Checks ────────────────────────────────────────────────────

    /**
     * Returns true if the stream supports seeking (rewinding, seeking to offset).
     */
    public function isSeekable(): bool
    {
        return $this->resource !== null
            && ($this->getMetadata('seekable') === true);
    }

    /**
     * Returns true if the stream is open for reading.
     */
    public function isReadable(): bool
    {
        if ($this->resource === null) {
            return false;
        }

        $mode = $this->getMetadata('mode');

        if (!is_string($mode)) {
            return false;
        }

        return str_contains($mode, 'r') || str_contains($mode, '+');
    }

    /**
     * Returns true if the stream is open for writing.
     */
    public function isWritable(): bool
    {
        if ($this->resource === null) {
            return false;
        }

        $mode = $this->getMetadata('mode');

        if (!is_string($mode)) {
            return false;
        }

        return str_contains($mode, 'w')
            || str_contains($mode, 'a')
            || str_contains($mode, 'x')
            || str_contains($mode, 'c')
            || str_contains($mode, '+');
    }

    // ─── Size / Metadata ──────────────────────────────────────────────────────

    /**
     * Returns the total size of the stream in bytes, or null if the size is unknown.
     * The result is cached after the first call for performance.
     */
    public function getSize(): ?int
    {
        if ($this->resource === null) {
            return null;
        }

        if ($this->cachedSize >= 0) {
            return $this->cachedSize;
        }

        $stats = fstat($this->resource);

        if ($stats === false || !isset($stats['size'])) {
            return null;
        }

        $this->cachedSize = $stats['size'];

        return $this->cachedSize;
    }

    /**
     * Returns stream metadata by key, or the full metadata array if no key is given.
     *
     * @return mixed
     */
    public function getMetadata(?string $key = null): mixed
    {
        if ($this->resource === null) {
            return $key !== null ? null : [];
        }

        // Cache the metadata to avoid repeated syscalls
        if ($this->metadata === null) {
            $this->metadata = stream_get_meta_data($this->resource);
        }

        if ($key === null) {
            return $this->metadata;
        }

        return $this->metadata[$key] ?? null;
    }

    // ─── Lifecycle ────────────────────────────────────────────────────────────

    /**
     * Closes the underlying resource handle.
     * After calling this, the stream is unusable. All subsequent read/write calls
     * will throw RuntimeException.
     */
    public function close(): void
    {
        if ($this->resource !== null) {
            fclose($this->resource);
        }

        $this->detach();
    }

    /**
     * Detaches the underlying resource from this Stream object and returns it.
     * After detaching, this Stream object is in an unusable state.
     * The caller takes ownership of the resource and is responsible for closing it.
     *
     * @return resource|null
     */
    public function detach(): mixed
    {
        $resource       = $this->resource;
        $this->resource = null;
        $this->metadata = null;
        $this->cachedSize = -1;

        return $resource;
    }

    /**
     * Automatically close the resource when the object is garbage collected.
     * This prevents resource leaks if close() is not called explicitly.
     */
    public function __destruct()
    {
        $this->close();
    }

    // ─── Chunk Iterator ───────────────────────────────────────────────────────

    /**
     * Yields the stream content in chunks of $chunkSize bytes.
     * This is the most memory-efficient way to process large response bodies
     * (e.g., downloading a large file) without loading everything into RAM.
     *
     * @param int $chunkSize The number of bytes to read per iteration (default: 8KB)
     * @return \Generator<int, string, null, void>
     */
    public function chunks(int $chunkSize = 8192): \Generator
    {
        $this->assertReadable();

        while (!$this->eof()) {
            $chunk = $this->read($chunkSize);

            if ($chunk !== '') {
                yield $chunk;
            }
        }
    }

    // ─── Internal Assertions ─────────────────────────────────────────────────

    private function assertAttached(): void
    {
        if ($this->resource === null) {
            throw new \RuntimeException(
                'Stream has been detached or closed. Cannot perform operation on a dead stream.'
            );
        }
    }

    private function assertReadable(): void
    {
        $this->assertAttached();

        if (!$this->isReadable()) {
            throw new \RuntimeException('Stream is not readable (opened in write-only mode).');
        }
    }

    private function assertWritable(): void
    {
        $this->assertAttached();

        if (!$this->isWritable()) {
            throw new \RuntimeException('Stream is not writable (opened in read-only mode).');
        }
    }

    private function assertSeekable(): void
    {
        $this->assertAttached();

        if (!$this->isSeekable()) {
            throw new \RuntimeException(
                'Stream is not seekable (e.g., network streams and stdin are not seekable).'
            );
        }
    }
}
