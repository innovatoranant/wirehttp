<?php

declare(strict_types=1);

namespace WireHttp\Tests\Integration;

use WireHttp\Http\Response;
use WireHttp\Http\Stream;
use WireHttp\Response\Hydrator\Attributes\JsonProperty;
use WireHttp\Tests\TestCase;
use WireHttp\Transport\Mock\MockResponseQueue;
use WireHttp\Wire;

// ─── DTOs for Integration Tests ────────────────────────────────────────────────

final class UserDto
{
    public readonly int    $id;
    public readonly string $name;
    public readonly string $email;
}

final class PaginatedUsersDto
{
    public readonly int   $total;
    public readonly int   $page;

    #[JsonProperty('data', type: UserDto::class, isArray: true)]
    public readonly array $data;
}

/**
 * WireTest — End-to-End Integration Tests via MockTransport
 *
 * These tests exercise the full WireHTTP pipeline:
 *   Wire facade → Client → RequestBuilder → MiddlewareStack → MockTransport → ResponseDecorator
 *
 * No real network calls are made. MockTransport returns pre-configured responses.
 */
final class WireTest extends TestCase
{
    // ─── Basic HTTP Methods ───────────────────────────────────────────────────

    public function test_get_request_returns_json_response(): void
    {
        $this->queueJson(['id' => 1, 'name' => 'Alice', 'email' => 'alice@example.com']);

        $response = Wire::get('https://api.example.com/users/1')->send();

        $this->assertSame(200, $response->status());
        $this->assertSame(1, $response->json('id'));
        $this->assertSame('Alice', $response->json('name'));

        $this->assertRequestCount(1);
        $this->assertRequestMethod(0, 'GET');
        $this->assertRequestUrl(0, 'https://api.example.com/users/1');
    }

    public function test_post_request_with_json_body(): void
    {
        $this->queueJson(['id' => 42, 'name' => 'Bob'], 201);

        $response = Wire::post('https://api.example.com/users')
            ->withJson(['name' => 'Bob', 'email' => 'bob@example.com'])
            ->send();

        $this->assertSame(201, $response->status());
        $this->assertTrue($response->isCreated());

        $this->assertRequestMethod(0, 'POST');
        $this->assertRequestHasHeader(0, 'content-type', 'application/json; charset=utf-8');
        $this->assertRequestJson(0, ['name' => 'Bob', 'email' => 'bob@example.com']);
    }

    public function test_put_request(): void
    {
        $this->queueJson(['id' => 1, 'name' => 'Alice Updated']);

        Wire::put('https://api.example.com/users/1')
            ->withJson(['name' => 'Alice Updated'])
            ->send();

        $this->assertRequestMethod(0, 'PUT');
        $this->assertRequestCount(1);
        $this->assertTrue(true); // satisfy phpunit
    }

    public function test_patch_request(): void
    {
        $this->queueJson(['id' => 1]);

        Wire::patch('https://api.example.com/users/1')
            ->withJson(['email' => 'new@email.com'])
            ->send();

        $this->assertRequestMethod(0, 'PATCH');
        $this->assertRequestCount(1);
        $this->assertTrue(true); // satisfy phpunit
    }

    public function test_delete_request_returns_no_content(): void
    {
        $this->mockQueue->push($this->emptyResponse(204));

        $response = Wire::delete('https://api.example.com/users/1')->send();

        $this->assertSame(204, $response->status());
        $this->assertTrue($response->isNoContent());
        $this->assertRequestMethod(0, 'DELETE');
    }

    // ─── Authentication ───────────────────────────────────────────────────────

    public function test_bearer_token_is_added_to_request(): void
    {
        $this->queueJson(['data' => 'secret']);

        Wire::get('https://api.example.com/protected')
            ->withBearer('my-secret-token')
            ->send();

        $this->assertRequestHasHeader(0, 'authorization', 'Bearer my-secret-token');
    }

    public function test_basic_auth_encodes_credentials(): void
    {
        $this->queueJson(['ok' => true]);

        Wire::get('https://api.example.com/auth')
            ->withBasicAuth('alice', 'password123')
            ->send();

        $expectedAuth = 'Basic ' . base64_encode('alice:password123');
        $this->assertRequestHasHeader(0, 'authorization', $expectedAuth);
    }

    // ─── Query Parameters ─────────────────────────────────────────────────────

    public function test_query_parameters_are_appended_to_uri(): void
    {
        $this->queueJson(['users' => []]);

        Wire::get('https://api.example.com/users')
            ->withQuery(['page' => 2, 'limit' => 20])
            ->send();

        $history = $this->mockQueue->getRequestHistory();
        $uri     = (string) $history[0]->getUri();

        $this->assertStringContainsString('page=2', $uri);
        $this->assertStringContainsString('limit=20', $uri);
    }

    // ─── Response Decorator ───────────────────────────────────────────────────

    public function test_response_decorator_is_ok_on_200(): void
    {
        $this->queueJson([]);

        $response = Wire::get('https://api.example.com')->send();

        $this->assertTrue($response->isOk());
        $this->assertTrue($response->isSuccessful());
        $this->assertFalse($response->failed());
    }

    public function test_response_is_not_found_on_404(): void
    {
        $this->mockQueue->push(new Response(404, [], Stream::fromString('Not Found')));

        $response = Wire::get('https://api.example.com/missing')->send();

        $this->assertTrue($response->isNotFound());
        $this->assertTrue($response->isClientError());
        $this->assertTrue($response->failed());
    }

    public function test_response_is_server_error_on_500(): void
    {
        $this->mockQueue->push($this->serverErrorResponse());

        $response = Wire::get('https://api.example.com/broken')->send();

        $this->assertTrue($response->isServerError());
        $this->assertTrue($response->failed());
    }

    // ─── Throw On Error ───────────────────────────────────────────────────────

    public function test_throw_raises_exception_on_4xx(): void
    {
        $this->mockQueue->push(new Response(401, [], Stream::fromString('Unauthorized')));

        $this->expectException(\WireHttp\Exceptions\ClientException::class);

        Wire::get('https://api.example.com/secret')->throw()->send();
    }

    public function test_throw_raises_exception_on_5xx(): void
    {
        $this->mockQueue->push($this->serverErrorResponse());

        $this->expectException(\WireHttp\Exceptions\ServerException::class);

        Wire::get('https://api.example.com/broken')->throw()->send();
    }

    public function test_throw_does_not_raise_on_2xx(): void
    {
        $this->queueJson(['ok' => true]);

        $response = Wire::get('https://api.example.com/ok')->throw()->send();

        $this->assertTrue($response->isOk());
    }

    // ─── JSON Dot-Notation Access ─────────────────────────────────────────────

    public function test_json_dot_notation_accesses_nested_keys(): void
    {
        $this->queueJson([
            'user' => [
                'address' => [
                    'city' => 'London',
                ],
            ],
        ]);

        $response = Wire::get('https://api.example.com/me')->send();

        $this->assertSame('London', $response->json('user.address.city'));
    }

    public function test_json_dot_notation_returns_null_for_missing_key(): void
    {
        $this->queueJson(['user' => ['name' => 'Alice']]);

        $response = Wire::get('https://api.example.com/me')->send();

        $this->assertNull($response->json('user.nonexistent.key'));
    }

    // ─── DTO Hydration ────────────────────────────────────────────────────────

    public function test_into_hydrates_response_into_dto(): void
    {
        $this->queueJson(['id' => 7, 'name' => 'Alice', 'email' => 'alice@test.com']);

        $user = Wire::get('https://api.example.com/users/7')->send()->into(UserDto::class);

        $this->assertInstanceOf(UserDto::class, $user);
        $this->assertSame(7, $user->id);
        $this->assertSame('Alice', $user->name);
        $this->assertSame('alice@test.com', $user->email);
    }

    public function test_into_hydrates_paginated_response_with_array_of_dtos(): void
    {
        $this->queueJson([
            'total' => 2,
            'page'  => 1,
            'data'  => [
                ['id' => 1, 'name' => 'Alice', 'email' => 'a@a.com'],
                ['id' => 2, 'name' => 'Bob',   'email' => 'b@b.com'],
            ],
        ]);

        $paginated = Wire::get('https://api.example.com/users')->send()->into(PaginatedUsersDto::class);

        $this->assertSame(2, $paginated->total);
        $this->assertCount(2, $paginated->data);
        $this->assertInstanceOf(UserDto::class, $paginated->data[0]);
        $this->assertSame('Alice', $paginated->data[0]->name);
    }

    // ─── Form Body ────────────────────────────────────────────────────────────

    public function test_form_body_sets_correct_content_type(): void
    {
        $this->queueJson(['ok' => true]);

        Wire::post('https://api.example.com/login')
            ->withForm(['username' => 'alice', 'password' => 'secret'])
            ->send();

        $this->assertRequestHasHeader(0, 'content-type', 'application/x-www-form-urlencoded');
    }

    // ─── Multiple Sequential Requests ────────────────────────────────────────

    public function test_multiple_sequential_requests_consume_queue_in_order(): void
    {
        $this->queueJson(['id' => 1, 'name' => 'Alice', 'email' => 'a@a.com']);
        $this->queueJson(['id' => 2, 'name' => 'Bob', 'email' => 'b@b.com']);

        $alice = Wire::get('https://api.example.com/users/1')->send();
        $bob   = Wire::get('https://api.example.com/users/2')->send();

        $this->assertSame(1, $alice->json('id'));
        $this->assertSame(2, $bob->json('id'));
        $this->assertRequestCount(2);
    }

    // ─── Body Helpers ─────────────────────────────────────────────────────────

    public function test_body_and_text_return_raw_body_string(): void
    {
        $this->mockQueue->push($this->textResponse('Hello from server'));

        $response = Wire::get('https://api.example.com')->send();

        $this->assertSame('Hello from server', $response->body());
        $this->assertSame('Hello from server', $response->text());
    }

    public function test_body_is_cached_across_multiple_calls(): void
    {
        $this->mockQueue->push($this->textResponse('cached'));

        $response = Wire::get('https://api.example.com')->send();

        $first  = $response->body();
        $second = $response->body(); // Should use cache, not re-read stream

        $this->assertSame('cached', $first);
        $this->assertSame('cached', $second);
    }

    // ─── Header Access ────────────────────────────────────────────────────────

    public function test_content_type_returns_response_content_type(): void
    {
        $this->queueJson([]);

        $response = Wire::get('https://api.example.com')->send();

        $this->assertStringContainsString('application/json', $response->contentType());
    }

    public function test_has_header_check(): void
    {
        $this->mockQueue->push(new Response(200, [
            'X-Custom-Header' => ['custom-value'],
        ], Stream::fromString('')));

        $response = Wire::get('https://api.example.com')->send();

        $this->assertTrue($response->hasHeader('X-Custom-Header'));
        $this->assertFalse($response->hasHeader('X-Non-Existent'));
        $this->assertSame('custom-value', $response->header('X-Custom-Header'));
    }

    // ─── Wire::fake assertions ────────────────────────────────────────────────

    public function test_wire_assert_request_count(): void
    {
        $this->queueJson([]);
        $this->queueJson([]);

        Wire::get('https://api.example.com/a')->send();
        Wire::get('https://api.example.com/b')->send();

        Wire::assertRequestCount(2);
        $this->assertTrue(true); // satisfy phpunit
    }

    public function test_wire_assert_request_url(): void
    {
        $this->queueJson([]);

        Wire::get('https://api.example.com/users')->send();

        Wire::assertRequestUrl(0, 'https://api.example.com/users');
        $this->assertTrue(true); // satisfy phpunit
    }

    public function test_queue_underflow_throws_when_too_many_requests_made(): void
    {
        // Only one response queued
        $this->queueJson([]);

        Wire::get('https://api.example.com/a')->send();

        // Second request — queue is empty
        $this->expectException(\UnderflowException::class);

        Wire::get('https://api.example.com/b')->send();
    }
}
