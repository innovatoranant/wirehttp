<?php

declare(strict_types=1);

namespace WireHttp\Request\Payload;

use WireHttp\Http\Stream;

/**
 * MultipartPayload — Encodes Data as multipart/form-data
 *
 * Used for file uploads and mixed text/binary form submissions.
 * Each "part" in the payload can be a text field, an uploaded file
 * (from a file path or raw binary data), or a Stream.
 *
 * Multipart Format (RFC 2046):
 * ----------------------------
 *   Content-Type: multipart/form-data; boundary=----WireHTTP1234
 *
 *   ------WireHTTP1234
 *   Content-Disposition: form-data; name="username"
 *
 *   alice
 *   ------WireHTTP1234
 *   Content-Disposition: form-data; name="avatar"; filename="photo.jpg"
 *   Content-Type: image/jpeg
 *
 *   <binary JPEG data>
 *   ------WireHTTP1234--
 *
 * Usage:
 *   $builder->withMultipart([
 *       ['name' => 'username', 'contents' => 'alice'],
 *       ['name' => 'avatar',   'contents' => fopen('/path/to/photo.jpg', 'rb'),
 *        'filename' => 'photo.jpg', 'mime' => 'image/jpeg'],
 *       ['name' => 'document', 'filepath' => '/path/to/report.pdf'],
 *   ]);
 */
final class MultipartPayload
{
    /**
     * Encodes multipart parts into a body stream.
     *
     * Each part must be an array with:
     *   - `name`      (required): The form field name.
     *   - `contents`  (optional): The field value — string, resource, or Stream.
     *   - `filepath`  (optional): Path to a file (alternative to `contents`).
     *   - `filename`  (optional): The filename reported to the server (defaults to basename of filepath).
     *   - `mime`      (optional): The Content-Type for this part. Inferred from extension if omitted.
     *   - `headers`   (optional): Additional part-level headers as array<string, string>.
     *
     * @param list<array<string, mixed>> $parts
     *
     * @return array{0: Stream, 1: string, 2: int}  [body stream, content-type, content-length]
     * @throws \InvalidArgumentException If a part is missing a name or has no contents.
     */
    public static function encode(array $parts): array
    {
        $boundary = '----WireHTTPBoundary' . bin2hex(random_bytes(16));
        $body     = '';

        foreach ($parts as $i => $part) {
            if (!isset($part['name'])) {
                throw new \InvalidArgumentException(
                    sprintf('Multipart part at index %d must have a "name" key.', $i)
                );
            }

            $name     = (string) $part['name'];
            $filename = $part['filename'] ?? null;
            $mime     = $part['mime'] ?? null;
            $extra    = $part['headers'] ?? [];

            // Resolve contents from either 'contents' or 'filepath'
            if (isset($part['filepath'])) {
                $filepath = (string) $part['filepath'];

                if (!is_file($filepath) || !is_readable($filepath)) {
                    throw new \InvalidArgumentException(
                        "Multipart: file '{$filepath}' does not exist or is not readable."
                    );
                }

                $contents = (string) file_get_contents($filepath);
                $filename = $filename ?? basename($filepath);
                $mime     = $mime ?? self::guessMime($filepath);
            } elseif (isset($part['contents'])) {
                $raw = $part['contents'];

                if (is_resource($raw)) {
                    $contents = stream_get_contents($raw);

                    if ($contents === false) {
                        throw new \InvalidArgumentException(
                            "Multipart: could not read resource for field '{$name}'."
                        );
                    }
                } elseif ($raw instanceof Stream) {
                    if ($raw->isSeekable()) {
                        $raw->rewind();
                    }

                    $contents = $raw->getContents();
                } else {
                    $contents = (string) $raw;
                }
            } else {
                throw new \InvalidArgumentException(
                    sprintf('Multipart part "%s" must have "contents" or "filepath".', $name)
                );
            }

            // Build MIME headers for this part
            $disposition = sprintf('form-data; name="%s"', $name);

            if ($filename !== null) {
                $disposition .= sprintf('; filename="%s"', $filename);
            }

            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: {$disposition}\r\n";

            if ($mime !== null) {
                $body .= "Content-Type: {$mime}\r\n";
            }

            // Additional part-level headers
            foreach ($extra as $headerName => $headerValue) {
                $body .= "{$headerName}: {$headerValue}\r\n";
            }

            $body .= "\r\n";
            $body .= $contents;
            $body .= "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        $contentType = "multipart/form-data; boundary={$boundary}";
        $length      = strlen($body);

        return [
            Stream::fromString($body),
            $contentType,
            $length,
        ];
    }

    /**
     * Guesses the MIME type of a file from its extension.
     * Falls back to `application/octet-stream` for unknown types.
     */
    private static function guessMime(string $filepath): string
    {
        $ext = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'svg'         => 'image/svg+xml',
            'pdf'         => 'application/pdf',
            'json'        => 'application/json',
            'xml'         => 'application/xml',
            'txt'         => 'text/plain',
            'html'        => 'text/html',
            'csv'         => 'text/csv',
            'zip'         => 'application/zip',
            'gz'          => 'application/gzip',
            'mp4'         => 'video/mp4',
            'mp3'         => 'audio/mpeg',
            default       => 'application/octet-stream',
        };
    }
}
