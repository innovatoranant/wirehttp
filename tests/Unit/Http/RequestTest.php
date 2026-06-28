<?php

declare(strict_types=1);

namespace WireHttp\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use WireHttp\Http\Request;
use WireHttp\Http\Stream;
use WireHttp\Http\Uri;

final class RequestTest extends TestCase
{
    private function makeRequest(
        string $method = 'GET',
        string $uri = 'https://example.com',
        array  $headers = [],
    ): Request {
        return new Request($method, new Uri($uri), $headers);
    }

    // ─── Construction ─────────────────────────────────────────────────────────

    public function test_creates_request_with_method_and_uri(): void
    {
        $request = $this->makeRequest('POST', 'https://api.example.com/users');

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.example.com/users', (string) $request->getUri());
    }

    public function test_accepts_string_uri(): void
    {
        $request = new Request('GET', 'https://example.com/path');

        $this->assertSame('https://example.com/path', (string) $request->getUri());
    }

    public function test_default_body_is_empty_stream(): void
    {
        $request = $this->makeRequest();

        $this->assertSame(0, $request->getBody()->getSize());
        $this->assertSame('', (string) $request->getBody());
    }

    // ─── Immutability ─────────────────────────────────────────────────────────

    public function test_with_method_returns_new_instance(): void
    {
        $original = $this->makeRequest('GET');
        $modified = $original->withMethod('POST');

        $this->assertSame('GET', $original->getMethod());
        $this->assertSame('POST', $modified->getMethod());
        $this->assertNotSame($original, $modified);
    }

    public function test_with_uri_returns_new_instance(): void
    {
        $original = $this->makeRequest('GET', 'https://a.com');
        $modified = $original->withUri(new Uri('https://b.com'));

        $this->assertSame('https://a.com', (string) $original->getUri());
        $this->assertSame('https://b.com', (string) $modified->getUri());
        $this->assertNotSame($original, $modified);
    }

    public function test_with_header_returns_new_instance_without_mutating_original(): void
    {
        $original = $this->makeRequest('GET');
        $modified = $original->withHeader('X-Custom', 'value');

        $this->assertFalse($original->hasHeader('X-Custom'));
        $this->assertTrue($modified->hasHeader('X-Custom'));
        $this->assertSame('value', $modified->getHeaderLine('X-Custom'));
    }

    public function test_with_body_returns_new_instance(): void
    {
        $original = $this->makeRequest();
        $stream   = Stream::fromString('{"key":"val"}');
        $modified = $original->withBody($stream);

        $this->assertSame(0, $original->getBody()->getSize());
        $this->assertSame(13, $modified->getBody()->getSize());
        $this->assertNotSame($original, $modified);
    }

    // ─── Headers ─────────────────────────────────────────────────────────────

    public function test_get_header_returns_list_of_values(): void
    {
        $request = $this->makeRequest('GET', 'https://example.com', [
            'Accept' => ['application/json', 'text/html'],
        ]);

        $this->assertSame(['application/json', 'text/html'], $request->getHeader('Accept'));
    }

    public function test_get_header_line_joins_with_comma(): void
    {
        $request = $this->makeRequest('GET', 'https://example.com', [
            'Accept' => ['application/json', 'text/html'],
        ]);

        $this->assertSame('application/json, text/html', $request->getHeaderLine('Accept'));
    }

    public function test_has_header_case_insensitive(): void
    {
        $request = new Request('GET', 'https://example.com', [
            'Content-Type' => ['application/json'],
        ]);

        $this->assertTrue($request->hasHeader('content-type'));
        $this->assertTrue($request->hasHeader('CONTENT-TYPE'));
        $this->assertTrue($request->hasHeader('Content-Type'));
    }

    public function test_without_header_removes_header(): void
    {
        $request  = new Request('GET', 'https://example.com', [
            'Authorization' => ['Bearer token'],
            'Accept'        => ['application/json'],
        ]);

        $modified = $request->withoutHeader('Authorization');

        $this->assertFalse($modified->hasHeader('Authorization'));
        $this->assertTrue($modified->hasHeader('Accept'));
    }

    public function test_add_header_appends_value(): void
    {
        $request  = new Request('GET', 'https://example.com', [
            'X-Custom' => ['first'],
        ]);

        $modified = $request->withAddedHeader('X-Custom', 'second');

        $this->assertSame(['first', 'second'], $modified->getHeader('X-Custom'));
    }

    public function test_get_headers_returns_all_headers(): void
    {
        $request = new Request('GET', 'https://example.com', [
            'Accept'       => ['application/json'],
            'Content-Type' => ['text/plain'],
        ]);

        $headers = $request->getHeaders();

        $this->assertArrayHasKey('Accept', $headers);
        $this->assertArrayHasKey('Content-Type', $headers);
    }

    // ─── Protocol Version ─────────────────────────────────────────────────────

    public function test_default_protocol_version_is_1_1(): void
    {
        $request = $this->makeRequest();

        $this->assertSame('1.1', $request->getProtocolVersion());
    }

    public function test_with_protocol_version_returns_new_instance(): void
    {
        $original = $this->makeRequest();
        $modified = $original->withProtocolVersion('2');

        $this->assertSame('1.1', $original->getProtocolVersion());
        $this->assertSame('2', $modified->getProtocolVersion());
    }

    // ─── Method Enum ─────────────────────────────────────────────────────────

    public function test_get_method_enum_returns_backed_enum(): void
    {
        $request = $this->makeRequest('DELETE');

        $this->assertSame(\WireHttp\Enums\HttpMethod::DELETE, $request->getMethodEnum());
    }
}
