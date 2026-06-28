<?php

declare(strict_types=1);

namespace WireHttp\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use WireHttp\Http\Uri;

final class UriTest extends TestCase
{
    // ─── Parsing ─────────────────────────────────────────────────────────────

    public function test_parses_full_uri(): void
    {
        $uri = new Uri('https://user:pass@example.com:8080/path/to/resource?foo=bar&baz=qux#anchor');

        $this->assertSame('https', $uri->getScheme());
        $this->assertSame('user:pass', $uri->getUserInfo());
        $this->assertSame('example.com', $uri->getHost());
        $this->assertSame(8080, $uri->getPort());
        $this->assertSame('/path/to/resource', $uri->getPath());
        $this->assertSame('foo=bar&baz=qux', $uri->getQuery());
        $this->assertSame('anchor', $uri->getFragment());
    }

    public function test_parses_simple_uri(): void
    {
        $uri = new Uri('https://api.example.com/users');

        $this->assertSame('https', $uri->getScheme());
        $this->assertSame('api.example.com', $uri->getHost());
        $this->assertSame('/users', $uri->getPath());
        $this->assertNull($uri->getPort());
        $this->assertSame('', $uri->getQuery());
    }

    public function test_parses_http_uri_without_path(): void
    {
        $uri = new Uri('http://example.com');

        $this->assertSame('http', $uri->getScheme());
        $this->assertSame('example.com', $uri->getHost());
        $this->assertSame('', $uri->getPath());
    }

    public function test_lowercases_scheme_and_host(): void
    {
        $uri = new Uri('HTTPS://EXAMPLE.COM/Path');

        $this->assertSame('https', $uri->getScheme());
        $this->assertSame('example.com', $uri->getHost());
        $this->assertSame('/Path', $uri->getPath());
    }

    // ─── Immutable Modification ────────────────────────────────────────────────

    public function test_with_scheme_returns_new_instance(): void
    {
        $uri  = new Uri('http://example.com');
        $new  = $uri->withScheme('https');

        $this->assertSame('http', $uri->getScheme());
        $this->assertSame('https', $new->getScheme());
        $this->assertNotSame($uri, $new);
    }

    public function test_with_path_returns_new_instance(): void
    {
        $uri = new Uri('https://example.com/old');
        $new = $uri->withPath('/new');

        $this->assertSame('/old', $uri->getPath());
        $this->assertSame('/new', $new->getPath());
    }

    public function test_with_query_returns_new_instance(): void
    {
        $uri = new Uri('https://example.com?page=1');
        $new = $uri->withQuery('page=2&limit=50');

        $this->assertSame('page=1', $uri->getQuery());
        $this->assertSame('page=2&limit=50', $new->getQuery());
    }

    public function test_with_host_returns_new_instance(): void
    {
        $uri = new Uri('https://old.example.com');
        $new = $uri->withHost('new.example.com');

        $this->assertSame('old.example.com', $uri->getHost());
        $this->assertSame('new.example.com', $new->getHost());
    }

    // ─── String Serialization ─────────────────────────────────────────────────

    public function test_string_serialization_full_uri(): void
    {
        $original = 'https://user:pass@example.com:9000/path?q=1#frag';
        $uri      = new Uri($original);

        $this->assertSame($original, (string) $uri);
    }

    public function test_string_serialization_omits_default_port(): void
    {
        $uri = new Uri('https://example.com:443/path');

        // Port 443 is default for HTTPS — should be omitted
        $this->assertSame('https://example.com/path', (string) $uri);
    }

    public function test_string_serialization_omits_http_default_port(): void
    {
        $uri = new Uri('http://example.com:80/path');

        $this->assertSame('http://example.com/path', (string) $uri);
    }

    // ─── Effective Port ───────────────────────────────────────────────────────

    public function test_get_effective_port_https_default(): void
    {
        $uri = new Uri('https://example.com');

        $this->assertSame(443, $uri->getEffectivePort());
    }

    public function test_get_effective_port_http_default(): void
    {
        $uri = new Uri('http://example.com');

        $this->assertSame(80, $uri->getEffectivePort());
    }

    public function test_get_effective_port_explicit(): void
    {
        $uri = new Uri('https://example.com:9443');

        $this->assertSame(9443, $uri->getEffectivePort());
    }

    // ─── Relative Resolution ──────────────────────────────────────────────────

    public function test_resolve_absolute_location(): void
    {
        $base     = new Uri('https://example.com/old/path');
        $relative = new Uri('https://other.com/new/path');

        $resolved = $base->resolve($relative);

        $this->assertSame('https://other.com/new/path', (string) $resolved);
    }

    public function test_resolve_relative_path(): void
    {
        $base     = new Uri('https://example.com/api/v1/users');
        $relative = new Uri('/api/v2/items');

        $resolved = $base->resolve($relative);

        $this->assertSame('https://example.com/api/v2/items', (string) $resolved);
    }

    // ─── Authority ────────────────────────────────────────────────────────────

    public function test_get_authority_with_port(): void
    {
        $uri = new Uri('https://example.com:9000/path');

        $this->assertSame('example.com:9000', $uri->getAuthority());
    }

    public function test_get_authority_with_user_info(): void
    {
        $uri = new Uri('https://user:secret@example.com');

        $this->assertSame('user:secret@example.com', $uri->getAuthority());
    }

    public function test_get_authority_simple(): void
    {
        $uri = new Uri('https://example.com');

        $this->assertSame('example.com', $uri->getAuthority());
    }

    // ─── Edge Cases ───────────────────────────────────────────────────────────

    public function test_empty_uri_is_valid(): void
    {
        $uri = new Uri('');

        $this->assertSame('', $uri->getScheme());
        $this->assertSame('', $uri->getHost());
        $this->assertSame('', (string) $uri);
    }

    public function test_uri_without_scheme(): void
    {
        $uri = new Uri('/api/users?page=1');

        $this->assertSame('', $uri->getScheme());
        $this->assertSame('/api/users', $uri->getPath());
        $this->assertSame('page=1', $uri->getQuery());
    }
}
