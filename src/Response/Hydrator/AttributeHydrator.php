<?php

declare(strict_types=1);

namespace WireHttp\Response\Hydrator;

use WireHttp\Http\Response;
use WireHttp\Response\Hydrator\Attributes\JsonProperty;
use WireHttp\Exceptions\HydrationException;

/**
 * AttributeHydrator — Reflection-Based PHP 8 Attribute DTO Mapper
 *
 * The AttributeHydrator reads `#[JsonProperty]` attributes from DTO class
 * properties and maps JSON response data to those properties using PHP 8
 * Reflection. Crucially, it populates `readonly` constructor-promoted and
 * non-promoted properties alike via `ReflectionProperty::setValue()`.
 *
 * Reflection Cache:
 * -----------------
 * Reflecting a class on every request is expensive. The AttributeHydrator
 * maintains a static in-memory cache of reflected class metadata, keyed by
 * FQCN. This means the first hydration of a class pays the reflection cost;
 * all subsequent hydrations are served from cache — essentially free.
 *
 * Property Resolution (in priority order):
 * -----------------------------------------
 * 1. `#[JsonProperty('exact_key')]`        — explicit JSON key override.
 * 2. `#[JsonProperty('parent.child')]`     — dot-notation for nested data.
 * 3. Exact property name match             — `$firstName` matches `firstName`.
 * 4. Snake-case to camelCase conversion   — `$firstName` matches `first_name`.
 * 5. CamelCase to snake_case conversion   — `$first_name` matches `firstName`.
 *
 * Constructor Promotion Support:
 * --------------------------------
 * For readonly constructor-promoted properties, PHP makes the property
 * inaccessible after construction. The AttributeHydrator bypasses access
 * control using `ReflectionProperty::setAccessible(true)` so readonly
 * properties can still be set during hydration.
 *
 * IMPORTANT: To hydrate a class with readonly properties, the class must be
 * instantiated WITHOUT constructor arguments (using `newInstanceWithoutConstructor()`).
 * This means your DTOs must NOT have constructor logic that fails when called
 * with no arguments. Use the `#[JsonProperty(required: true)]` attribute to
 * express constraints instead of constructor validation.
 *
 * Nested DTO Hydration:
 * ----------------------
 *   final class OrderDto {
 *       #[JsonProperty('user', type: UserDto::class)]
 *       public readonly UserDto $user;
 *
 *       #[JsonProperty('items', type: ItemDto::class, isArray: true)]
 *       public readonly array $items; // list<ItemDto>
 *   }
 *
 * Transform Callbacks:
 * ---------------------
 *   #[JsonProperty('created_at', transform: 'strtotime')]
 *   public readonly int $createdAt;   // Raw "2024-01-01T00:00:00Z" → 1704067200
 *
 *   #[JsonProperty('price', transform: fn($v) => (float) $v * 100)]
 *   public readonly int $priceInCents;
 *
 * Usage:
 *   $hydrator = new AttributeHydrator();
 *   $user = $hydrator->hydrate(UserDto::class, $response);
 *   $user = $hydrator->hydrateFromArray(UserDto::class, $data);
 */
final class AttributeHydrator implements HydratorInterface
{
    /**
     * Per-class metadata cache: stores resolved property mappings.
     * Structure: FQCN => list<PropertyMapping>
     *
     * Static so it persists across hydrator instances within the same request.
     *
     * @var array<class-string, list<PropertyMapping>>
     */
    private static array $metaCache = [];

    // ─── HydratorInterface ────────────────────────────────────────────────────

    /**
     * Hydrates the response JSON body into a DTO of the given class.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function hydrate(string $class, Response $response): object
    {
        $json = (string) $response->getBody();

        if ($json === '' || $json === null) {
            throw new HydrationException(
                message: 'Cannot hydrate: the response body is empty.',
                targetClass: $class,
            );
        }

        return $this->hydrateFromJson($class, (string) $json);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function hydrateFromJson(string $class, string $json): object
    {
        try {
            $data = json_decode($json, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new HydrationException(
                message: 'Cannot hydrate: malformed JSON — ' . $e->getMessage(),
                targetClass: $class,
                previous: $e,
            );
        }

        if (!is_array($data)) {
            throw new HydrationException(
                message: sprintf(
                    'Cannot hydrate: JSON root must be an object (array), got %s.',
                    gettype($data)
                ),
                targetClass: $class,
            );
        }

        return $this->hydrateFromArray($class, $data);
    }

    /**
     * @template T of object
     * @param class-string<T>      $class
     * @param array<string, mixed> $data
     * @return T
     */
    public function hydrateFromArray(string $class, array $data): object
    {
        $mappings = $this->getPropertyMappings($class);

        try {
            $reflClass = new \ReflectionClass($class);
            /** @var T $instance */
            $instance = $reflClass->newInstanceWithoutConstructor();
        } catch (\ReflectionException $e) {
            throw new HydrationException(
                message: "Cannot instantiate {$class}: " . $e->getMessage(),
                targetClass: $class,
                previous: $e,
            );
        }

        foreach ($mappings as $mapping) {
            $raw = $this->extractValue($data, $mapping->jsonKey);

            // Handle missing value
            if ($raw === self::MISSING) {
                if ($mapping->required) {
                    throw new HydrationException(
                        message: sprintf(
                            'Required field "%s" (mapped from JSON key "%s") is missing in the response.',
                            $mapping->propertyName,
                            $mapping->jsonKey
                        ),
                        targetClass: $class,
                        fieldName: $mapping->propertyName,
                    );
                }

                $raw = $mapping->default;

                if ($raw === null) {
                    continue; // skip — keep PHP default (null/uninitialized)
                }
            }

            // Apply type coercion for nested DTOs
            $value = $this->coerce($raw, $mapping, $class);

            // Apply user-defined transform
            if ($mapping->transform !== null) {
                try {
                    $value = ($mapping->transform)($value);
                } catch (\Throwable $e) {
                    throw new HydrationException(
                        message: sprintf(
                            'Transform for field "%s" threw: %s',
                            $mapping->propertyName,
                            $e->getMessage()
                        ),
                        targetClass: $class,
                        fieldName: $mapping->propertyName,
                        previous: $e,
                    );
                }
            }

            // Set the property value (bypasses readonly access)
            $mapping->reflProp->setValue($instance, $value);
        }

        return $instance;
    }

    // ─── Private: Metadata Resolution ─────────────────────────────────────────

    /**
     * Reflects a class and builds PropertyMapping objects for every property.
     * Results are cached statically per class.
     *
     * @param class-string $class
     * @return list<PropertyMapping>
     */
    private function getPropertyMappings(string $class): array
    {
        if (isset(self::$metaCache[$class])) {
            return self::$metaCache[$class];
        }

        try {
            $reflClass = new \ReflectionClass($class);
        } catch (\ReflectionException $e) {
            throw new HydrationException(
                message: "Class {$class} does not exist.",
                targetClass: $class,
                previous: $e,
            );
        }

        $mappings   = [];
        $properties = $reflClass->getProperties();

        foreach ($properties as $reflProp) {
            // Skip static properties
            if ($reflProp->isStatic()) {
                continue;
            }

            // Make private/protected/readonly properties accessible
            $reflProp->setAccessible(true);

            // Read the #[JsonProperty] attribute if present
            $attrs     = $reflProp->getAttributes(JsonProperty::class);
            $attribute = !empty($attrs) ? $attrs[0]->newInstance() : null;

            $propName  = $reflProp->getName();

            // Resolve the JSON key to use
            $jsonKey   = $attribute?->name ?? $this->inferJsonKey($propName);

            // Resolve the transform callable
            $transform = null;

            if ($attribute?->transform !== null) {
                $rawTransform = $attribute->transform;

                if (is_string($rawTransform) && function_exists($rawTransform)) {
                    $transform = \Closure::fromCallable($rawTransform);
                } elseif (is_callable($rawTransform)) {
                    $transform = \Closure::fromCallable($rawTransform);
                }
            }

            $mappings[] = new PropertyMapping(
                propertyName: $propName,
                jsonKey: $jsonKey,
                reflProp: $reflProp,
                nestedType: $attribute?->type,
                isArray: $attribute?->isArray ?? false,
                required: $attribute?->required ?? false,
                default: $attribute?->default,
                transform: $transform,
            );
        }

        return self::$metaCache[$class] = $mappings;
    }

    /**
     * Infers the likely JSON key for a PHP property name.
     * Tries the property name as-is AND a snake_case version.
     * Returns an array of candidates; the extractor tries each in order.
     *
     * Example: `$firstName` → tries `firstName`, then `first_name`
     */
    private function inferJsonKey(string $propName): string
    {
        return $propName; // The extractor will also try snake_case conversion
    }

    // ─── Private: Value Extraction ────────────────────────────────────────────

    /** Sentinel for "key not found in data" */
    private const MISSING = '__WIRE_MISSING__';

    /**
     * Extracts a value from $data using a key (supports dot notation for nesting).
     *
     * @param array<string, mixed> $data
     * @return mixed The extracted value, or self::MISSING if not found.
     */
    private function extractValue(array $data, string $key): mixed
    {
        // Dot notation: "address.city" → $data['address']['city']
        if (str_contains($key, '.')) {
            $parts = explode('.', $key);
            $value = $data;

            foreach ($parts as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    return self::MISSING;
                }

                $value = $value[$part];
            }

            return $value;
        }

        // Exact key match
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        // Try snake_case conversion: firstName → first_name
        $snake = $this->camelToSnake($key);

        if ($snake !== $key && array_key_exists($snake, $data)) {
            return $data[$snake];
        }

        // Try camelCase conversion: first_name → firstName
        $camel = $this->snakeToCamel($key);

        if ($camel !== $key && array_key_exists($camel, $data)) {
            return $data[$camel];
        }

        return self::MISSING;
    }

    // ─── Private: Type Coercion ────────────────────────────────────────────────

    /**
     * Coerces a raw value to the expected type defined by the property mapping.
     * Handles nested DTO hydration and arrays of DTOs.
     */
    private function coerce(mixed $raw, PropertyMapping $mapping, string $parentClass): mixed
    {
        if ($mapping->nestedType === null || $raw === null) {
            return $raw;
        }

        // Array of DTOs: e.g., list<ItemDto>
        if ($mapping->isArray) {
            if (!is_array($raw)) {
                throw new HydrationException(
                    message: sprintf(
                        'Field "%s" expects an array for type "%s[]", got %s.',
                        $mapping->propertyName,
                        $mapping->nestedType,
                        gettype($raw)
                    ),
                    targetClass: $parentClass,
                    fieldName: $mapping->propertyName,
                );
            }

            return array_map(
                fn(mixed $item) => is_array($item)
                    ? $this->hydrateFromArray($mapping->nestedType, $item)
                    : $item,
                $raw
            );
        }

        // Single nested DTO
        if (is_array($raw)) {
            return $this->hydrateFromArray($mapping->nestedType, $raw);
        }

        return $raw;
    }

    // ─── Private: String Case Helpers ─────────────────────────────────────────

    private function camelToSnake(string $input): string
    {
        return strtolower((string) preg_replace('/[A-Z]/', '_$0', lcfirst($input)));
    }

    private function snakeToCamel(string $input): string
    {
        return lcfirst(str_replace('_', '', ucwords($input, '_')));
    }

    /**
     * Clears the static reflection cache.
     * Call this in tests to reset state between test cases.
     */
    public static function clearCache(): void
    {
        self::$metaCache = [];
    }
}

/**
 * PropertyMapping — Resolved Metadata for a Single DTO Property
 *
 * Created once per class property during reflection and stored in the cache.
 * All subsequent hydrations use these pre-resolved mappings.
 *
 * @internal
 */
final class PropertyMapping
{
    public function __construct(
        public readonly string              $propertyName,
        public readonly string              $jsonKey,
        public readonly \ReflectionProperty $reflProp,
        public readonly ?string             $nestedType = null,
        public readonly bool                $isArray = false,
        public readonly bool                $required = false,
        public readonly mixed               $default = null,
        public readonly ?\Closure           $transform = null,
    ) {
    }
}
