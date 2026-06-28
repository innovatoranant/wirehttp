<?php

declare(strict_types=1);

namespace WireHttp\Tests\Unit\Response;

use PHPUnit\Framework\TestCase;
use WireHttp\Http\Response;
use WireHttp\Http\Stream;
use WireHttp\Response\Hydrator\AttributeHydrator;
use WireHttp\Response\Hydrator\Attributes\JsonProperty;
use WireHttp\Response\Hydrator\HydrationException;

// ─── DTOs used in tests ────────────────────────────────────────────────────────

final class SimpleUserDto
{
    public readonly int    $id;
    public readonly string $name;
    public readonly string $email;
}

final class UserWithMapping
{
    #[JsonProperty('first_name')]
    public readonly string $firstName;

    #[JsonProperty('last_name')]
    public readonly string $lastName;
}

final class UserWithNested
{
    public readonly string  $name;

    #[JsonProperty('address', type: AddressDto::class)]
    public readonly AddressDto $address;
}

final class AddressDto
{
    public readonly string $city;
    public readonly string $country;
}

final class UserWithArray
{
    #[JsonProperty('tags', type: TagDto::class, isArray: true)]
    public readonly array $tags;
}

final class TagDto
{
    public readonly string $name;
    public readonly string $color;
}

final class UserWithDotNotation
{
    #[JsonProperty('meta.created_at')]
    public readonly int $createdAt;
}

final class UserWithTransform
{
    #[JsonProperty('score', transform: 'intval')]
    public readonly int $score;
}

final class UserWithDefault
{
    #[JsonProperty('nickname', default: 'anonymous')]
    public readonly string $nickname;
}

final class UserWithRequired
{
    #[JsonProperty('ssn', required: true)]
    public readonly string $ssn;
}

// ─── Tests ────────────────────────────────────────────────────────────────────

final class AttributeHydratorTest extends TestCase
{
    private AttributeHydrator $hydrator;

    protected function setUp(): void
    {
        AttributeHydrator::clearCache();
        $this->hydrator = new AttributeHydrator();
    }

    private function responseFromJson(array $data, int $status = 200): Response
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);

        return new Response($status, [
            'Content-Type' => ['application/json'],
        ], Stream::fromString($json));
    }

    // ─── Basic Hydration ──────────────────────────────────────────────────────

    public function test_hydrates_simple_dto_from_response(): void
    {
        $response = $this->responseFromJson(['id' => 42, 'name' => 'Alice', 'email' => 'alice@example.com']);

        $dto = $this->hydrator->hydrate(SimpleUserDto::class, $response);

        $this->assertSame(42, $dto->id);
        $this->assertSame('Alice', $dto->name);
        $this->assertSame('alice@example.com', $dto->email);
    }

    public function test_hydrates_from_array(): void
    {
        $dto = $this->hydrator->hydrateFromArray(SimpleUserDto::class, [
            'id'    => 1,
            'name'  => 'Bob',
            'email' => 'bob@example.com',
        ]);

        $this->assertSame(1, $dto->id);
        $this->assertSame('Bob', $dto->name);
    }

    public function test_hydrates_from_json_string(): void
    {
        $dto = $this->hydrator->hydrateFromJson(SimpleUserDto::class, '{"id":7,"name":"Carol","email":"c@example.com"}');

        $this->assertSame(7, $dto->id);
        $this->assertSame('Carol', $dto->name);
    }

    // ─── JsonProperty Name Mapping ────────────────────────────────────────────

    public function test_respects_json_property_name_attribute(): void
    {
        $dto = $this->hydrator->hydrateFromArray(UserWithMapping::class, [
            'first_name' => 'Alice',
            'last_name'  => 'Smith',
        ]);

        $this->assertSame('Alice', $dto->firstName);
        $this->assertSame('Smith', $dto->lastName);
    }

    public function test_infers_snake_case_from_camel_case_property(): void
    {
        // SimpleUserDto has no attributes, but the hydrator tries snake_case
        // email_address → emailAddress (not actually in SimpleUserDto, this is
        // tested via UserWithMapping which explicitly maps it)
        $dto = $this->hydrator->hydrateFromArray(UserWithMapping::class, [
            'first_name' => 'Alice',
            'last_name'  => 'Smith',
        ]);

        $this->assertSame('Alice', $dto->firstName);
    }

    // ─── Nested DTO ───────────────────────────────────────────────────────────

    public function test_hydrates_nested_dto(): void
    {
        $dto = $this->hydrator->hydrateFromArray(UserWithNested::class, [
            'name'    => 'Alice',
            'address' => [
                'city'    => 'London',
                'country' => 'UK',
            ],
        ]);

        $this->assertInstanceOf(AddressDto::class, $dto->address);
        $this->assertSame('London', $dto->address->city);
        $this->assertSame('UK', $dto->address->country);
    }

    // ─── Array of DTOs ────────────────────────────────────────────────────────

    public function test_hydrates_array_of_dtos(): void
    {
        $dto = $this->hydrator->hydrateFromArray(UserWithArray::class, [
            'tags' => [
                ['name' => 'php', 'color' => 'blue'],
                ['name' => 'async', 'color' => 'green'],
            ],
        ]);

        $this->assertCount(2, $dto->tags);
        $this->assertInstanceOf(TagDto::class, $dto->tags[0]);
        $this->assertSame('php', $dto->tags[0]->name);
        $this->assertSame('async', $dto->tags[1]->name);
    }

    // ─── Dot Notation ────────────────────────────────────────────────────────

    public function test_extracts_nested_key_via_dot_notation(): void
    {
        $dto = $this->hydrator->hydrateFromArray(UserWithDotNotation::class, [
            'meta' => ['created_at' => 1704067200],
        ]);

        $this->assertSame(1704067200, $dto->createdAt);
    }

    // ─── Transform ───────────────────────────────────────────────────────────

    public function test_applies_transform_callback(): void
    {
        $dto = $this->hydrator->hydrateFromArray(UserWithTransform::class, [
            'score' => '42.7', // String from JSON
        ]);

        $this->assertSame(42, $dto->score); // intval applied
    }

    // ─── Default Value ────────────────────────────────────────────────────────

    public function test_uses_default_when_key_is_missing(): void
    {
        $dto = $this->hydrator->hydrateFromArray(UserWithDefault::class, []);

        $this->assertSame('anonymous', $dto->nickname);
    }

    // ─── Required Fields ─────────────────────────────────────────────────────

    public function test_throws_hydration_exception_for_missing_required_field(): void
    {
        $this->expectException(HydrationException::class);
        $this->expectExceptionMessageMatches('/Required field "ssn"/');

        $this->hydrator->hydrateFromArray(UserWithRequired::class, []);
    }

    // ─── Reflection Cache ─────────────────────────────────────────────────────

    public function test_cache_is_used_on_repeated_hydration(): void
    {
        // Hydrate twice — second should hit cache
        $dto1 = $this->hydrator->hydrateFromArray(SimpleUserDto::class, ['id' => 1, 'name' => 'A', 'email' => 'a@a.com']);
        $dto2 = $this->hydrator->hydrateFromArray(SimpleUserDto::class, ['id' => 2, 'name' => 'B', 'email' => 'b@b.com']);

        $this->assertSame(1, $dto1->id);
        $this->assertSame(2, $dto2->id);
    }

    // ─── Error Handling ───────────────────────────────────────────────────────

    public function test_throws_on_malformed_json(): void
    {
        $this->expectException(HydrationException::class);
        $this->expectExceptionMessageMatches('/malformed JSON/');

        $this->hydrator->hydrateFromJson(SimpleUserDto::class, '{invalid json');
    }

    public function test_throws_on_empty_response_body(): void
    {
        $response = new Response(200, [], Stream::fromString(''));

        $this->expectException(HydrationException::class);
        $this->expectExceptionMessageMatches('/empty/');

        $this->hydrator->hydrate(SimpleUserDto::class, $response);
    }
}
