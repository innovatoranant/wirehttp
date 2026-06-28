<?php

declare(strict_types=1);

namespace WireHttp\Http;

/**
 * UploadedFile — RFC 7578 Multipart File Upload Handler
 *
 * Represents a single file that was uploaded as part of a multipart/form-data request.
 * This is used both for sending files (building outbound multipart requests via
 * MultipartPayload) and for testing (mocking uploaded files in integration tests).
 *
 * RFC 7578 §4.2 defines the structure of a file part in a multipart body:
 *   - Content-Disposition header with `name` and optional `filename` parameters.
 *   - Optional Content-Type header for the file's MIME type.
 *   - The file data as the part body.
 *
 * This class wraps either:
 *   1. An existing Stream (e.g., a file already opened on disk).
 *   2. A path string (the file is opened lazily when the stream is first accessed).
 *
 * Error Handling:
 * ---------------
 * We track the PHP upload error code to allow proper validation. The standard
 * PHP upload errors are: UPLOAD_ERR_OK, UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE,
 * UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_NO_TMP_DIR,
 * UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION.
 *
 * Even though WireHTTP is a client library and doesn't receive uploads, we include
 * error code handling for full completeness and for use in testing environments.
 */
final class UploadedFile
{
    /**
     * The underlying stream for this file. Populated lazily if a path is given.
     */
    private ?Stream $stream;

    /**
     * The optional file path used for lazy stream initialization.
     */
    private readonly ?string $filePath;

    /**
     * The client-provided filename (e.g., "photo.jpg").
     * This is what the browser reports — it's NOT the actual server-side file path.
     * Always treat this as untrusted user input.
     */
    private readonly ?string $clientFilename;

    /**
     * The client-reported MIME type (e.g., "image/jpeg").
     * Also untrusted — never use this alone to validate file type.
     */
    private readonly ?string $clientMediaType;

    /**
     * The file size in bytes, or null if unknown.
     */
    private readonly ?int $size;

    /**
     * The PHP upload error code (one of the UPLOAD_ERR_* constants).
     * UPLOAD_ERR_OK (0) means no error.
     */
    private readonly int $error;

    /**
     * True after `moveTo()` has been called. Prevents double-moving a file.
     */
    private bool $moved = false;

    /**
     * Human-readable error messages for each UPLOAD_ERR_* constant.
     */
    private const UPLOAD_ERRORS = [
        UPLOAD_ERR_OK         => 'There is no error, the file uploaded with success.',
        UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
        UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.',
        UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
    ];

    public function __construct(
        Stream|string|null $streamOrPath,
        ?int $size = null,
        int $error = UPLOAD_ERR_OK,
        ?string $clientFilename = null,
        ?string $clientMediaType = null,
    ) {
        $this->error          = $error;
        $this->size           = $size;
        $this->clientFilename = $clientFilename;
        $this->clientMediaType = $clientMediaType;

        if ($streamOrPath instanceof Stream) {
            $this->stream   = $streamOrPath;
            $this->filePath = null;
        } elseif (is_string($streamOrPath)) {
            $this->stream   = null;
            $this->filePath = $streamOrPath;
        } else {
            $this->stream   = null;
            $this->filePath = null;
        }

        if (!array_key_exists($this->error, self::UPLOAD_ERRORS)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid upload error code: %d.', $this->error)
            );
        }
    }

    // ─── Factory Methods ──────────────────────────────────────────────────────

    /**
     * Creates an UploadedFile from a file path on disk.
     * The stream is opened lazily when first accessed.
     */
    public static function fromPath(
        string $path,
        ?string $clientFilename = null,
        ?string $clientMediaType = null,
    ): static {
        if (!file_exists($path) || !is_readable($path)) {
            throw new \InvalidArgumentException(
                sprintf('File does not exist or is not readable: "%s".', $path)
            );
        }

        return new static(
            streamOrPath: $path,
            size: filesize($path) ?: null,
            clientFilename: $clientFilename ?? basename($path),
            clientMediaType: $clientMediaType ?? (mime_content_type($path) ?: 'application/octet-stream'),
        );
    }

    /**
     * Creates an UploadedFile from an in-memory string.
     * Useful in tests to mock file uploads without needing real files on disk.
     */
    public static function fromString(
        string $content,
        string $clientFilename = 'file',
        string $clientMediaType = 'application/octet-stream',
    ): static {
        return new static(
            streamOrPath: Stream::fromString($content),
            size: strlen($content),
            clientFilename: $clientFilename,
            clientMediaType: $clientMediaType,
        );
    }

    // ─── Core API ─────────────────────────────────────────────────────────────

    /**
     * Returns the underlying Stream for this file.
     *
     * @throws \RuntimeException if the upload had an error, the file was moved,
     *                            or the stream cannot be opened.
     */
    public function getStream(): Stream
    {
        $this->assertUsable();

        if ($this->stream === null && $this->filePath !== null) {
            $this->stream = Stream::fromFile($this->filePath, 'rb');
        }

        if ($this->stream === null) {
            throw new \RuntimeException('No stream is available for this uploaded file.');
        }

        return $this->stream;
    }

    /**
     * Moves the uploaded file to a new location on disk.
     * Can only be called once — subsequent calls throw RuntimeException.
     *
     * @param string $targetPath The absolute path to move the file to.
     * @throws \RuntimeException if the file was already moved or on write failure.
     */
    public function moveTo(string $targetPath): void
    {
        $this->assertUsable();

        $targetDir = dirname($targetPath);

        if (!is_dir($targetDir) || !is_writable($targetDir)) {
            throw new \RuntimeException(
                sprintf('Target directory is not writable: "%s".', $targetDir)
            );
        }

        if ($this->filePath !== null && PHP_SAPI !== 'cli') {
            // Use the optimized PHP upload move for server context
            if (!move_uploaded_file($this->filePath, $targetPath)) {
                throw new \RuntimeException(
                    sprintf('Failed to move uploaded file to "%s".', $targetPath)
                );
            }
        } else {
            // Stream copy (CLI context, or stream-based file)
            $targetStream = Stream::fromFile($targetPath, 'wb');
            $sourceStream = $this->getStream();

            if ($sourceStream->isSeekable()) {
                $sourceStream->rewind();
            }

            foreach ($sourceStream->chunks() as $chunk) {
                $targetStream->write($chunk);
            }

            $targetStream->close();
        }

        $this->moved = true;

        // Release the stream after moving
        if ($this->stream !== null) {
            $this->stream->close();
            $this->stream = null;
        }
    }

    /**
     * Returns the file size in bytes, or null if unknown.
     */
    public function getSize(): ?int
    {
        return $this->size;
    }

    /**
     * Returns the PHP upload error code (UPLOAD_ERR_* constant).
     */
    public function getError(): int
    {
        return $this->error;
    }

    /**
     * Returns true if there is no error with the uploaded file.
     */
    public function isOk(): bool
    {
        return $this->error === UPLOAD_ERR_OK;
    }

    /**
     * Returns a human-readable error description for the current upload error code.
     */
    public function getErrorMessage(): string
    {
        return self::UPLOAD_ERRORS[$this->error]
            ?? sprintf('Unknown upload error code: %d', $this->error);
    }

    /**
     * Returns the client-provided filename (untrusted). Returns null if none was given.
     */
    public function getClientFilename(): ?string
    {
        return $this->clientFilename;
    }

    /**
     * Returns the client-reported MIME type (untrusted). Returns null if none was given.
     */
    public function getClientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    /**
     * Returns true if the file has already been moved via `moveTo()`.
     */
    public function isMoved(): bool
    {
        return $this->moved;
    }

    /**
     * Returns the file extension based on the client filename, or empty string if none.
     */
    public function getClientExtension(): string
    {
        if ($this->clientFilename === null) {
            return '';
        }

        return pathinfo($this->clientFilename, PATHINFO_EXTENSION);
    }

    // ─── Private Helpers ─────────────────────────────────────────────────────

    private function assertUsable(): void
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(
                sprintf(
                    'Cannot retrieve stream due to upload error: [%d] %s',
                    $this->error,
                    $this->getErrorMessage()
                )
            );
        }

        if ($this->moved) {
            throw new \RuntimeException(
                'Cannot retrieve stream after the uploaded file has been moved.'
            );
        }
    }
}
