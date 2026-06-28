<?php

declare(strict_types=1);

namespace WireHttp\Http\Factory;

use WireHttp\Http\Stream;

/**
 * StreamFactory — Creates Stream instances from various sources.
 *
 * WireHTTP's implementation of PSR-17's StreamFactoryInterface.
 * Provides named constructors for creating Stream objects from strings,
 * files, resources, and temp storage.
 */
final class StreamFactory
{
    /**
     * Creates a new Stream from a string.
     * Uses php://memory for strings ≤ 2MB, and php://temp for larger ones.
     */
    public function createStream(string $content = ''): Stream
    {
        return Stream::fromString($content);
    }

    /**
     * Creates a new Stream from a file path on disk.
     *
     * @param string $filename The absolute path to the file.
     * @param string $mode     The fopen mode. Use 'rb' for binary reading (default).
     * @throws \RuntimeException if the file cannot be opened.
     */
    public function createStreamFromFile(string $filename, string $mode = 'rb'): Stream
    {
        return Stream::fromFile($filename, $mode);
    }

    /**
     * Creates a Stream from an already-open PHP resource handle.
     *
     * @param resource $resource A valid, open stream resource.
     * @throws \InvalidArgumentException if the value is not a stream resource.
     */
    public function createStreamFromResource(mixed $resource): Stream
    {
        return Stream::fromResource($resource);
    }

    /**
     * Creates a new empty, writable in-memory Stream.
     * Equivalent to `createStream('')`.
     */
    public function createEmpty(): Stream
    {
        return Stream::empty();
    }

    /**
     * Creates a Stream from a php://temp handle.
     * Data is held in memory until it exceeds $maxMemoryBytes (default 2MB),
     * after which PHP automatically spills it to a temporary file on disk.
     * This is the best choice for bodies of unknown/large size.
     *
     * @param int $maxMemoryBytes The in-memory threshold before disk spill.
     */
    public function createTempStream(int $maxMemoryBytes = 2 * 1024 * 1024): Stream
    {
        $resource = fopen("php://temp/maxmemory:{$maxMemoryBytes}", 'r+');

        if ($resource === false) {
            throw new \RuntimeException('Failed to open php://temp stream handle.');
        }

        return Stream::fromResource($resource);
    }

    /**
     * Creates a Stream backed by STDIN.
     * Useful for reading piped input in CLI scripts.
     */
    public function createFromStdin(): Stream
    {
        $resource = fopen('php://stdin', 'rb');

        if ($resource === false) {
            throw new \RuntimeException('Failed to open php://stdin stream handle.');
        }

        return Stream::fromResource($resource);
    }
}
