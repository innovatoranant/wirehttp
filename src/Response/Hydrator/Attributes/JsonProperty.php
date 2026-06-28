<?php

declare(strict_types=1);

namespace WireHttp\Response\Hydrator\Attributes;

/**
 * JsonProperty — PHP 8 Attribute for JSON → DTO Field Mapping
 *
 * Apply this attribute to a property in a DTO class to control how the
 * AttributeHydrator maps a JSON key to that property.
 *
 * Basic Usage:
 *   final class UserDto {
 *       #[JsonProperty('first_name')]
 *       public readonly string $firstName;
 *
 *       #[JsonProperty('address.city')]   // Nested key via dot notation
 *       public readonly string $city;
 *
 *       #[JsonProperty('roles', type: RoleDto::class)]  // Nested DTO
 *       public readonly array $roles;
 *
 *       #[JsonProperty('created_at', transform: 'strtotime')]  // Value transformer
 *       public readonly int $createdAt;
 *   }
 *
 * Without the attribute, the hydrator maps JSON keys to properties by name
 * (exact match and camelCase ↔ snake_case conversion are both attempted).
 *
 * Features:
 *  - `name`: The JSON key to read from. Supports dot notation for nested values.
 *  - `type`: The fully-qualified class name to hydrate nested objects or arrays of objects.
 *  - `transform`: A callable (or function name string) to transform the raw value.
 *  - `required`: If true and the key is missing, the hydrator throws a HydrationException.
 *  - `default`: Value to use when the key is absent and `required` is false.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class JsonProperty
{
    /**
     * @param string|null  $name      JSON key name (supports dot notation). Defaults to property name.
     * @param string|null  $type      FQCN for nested DTO hydration or array element type.
     * @param mixed        $transform A callable or function name to transform the raw value.
     * @param bool         $required  If true, throw HydrationException when the key is absent.
     * @param mixed        $default   Default value when key is absent and not required.
     * @param bool         $isArray   If true and $type is set, treat the value as a list of $type objects.
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $type = null,
        public readonly mixed $transform = null,
        public readonly bool $required = false,
        public readonly mixed $default = null,
        public readonly bool $isArray = false,
    ) {
    }
}
