<?php

declare(strict_types=1);

namespace WireHttp\Response\Hydrator;

use WireHttp\Http\Response;

/**
 * HydratorInterface — Contract for Response-to-DTO Hydration
 *
 * A Hydrator transforms a raw HTTP Response (or a raw data array/scalar)
 * into a typed PHP object (a DTO / Value Object).
 *
 * Usage:
 *   $dto = $hydrator->hydrate(UserDto::class, $response);
 */
interface HydratorInterface
{
    /**
     * Hydrates a Response into an instance of the given class.
     *
     * @template T of object
     * @param class-string<T> $class    The FQCN of the target DTO class.
     * @param Response        $response The HTTP response to read data from.
     *
     * @return T A fully populated instance of $class.
     *
     * @throws HydrationException If hydration fails (missing required fields, type errors, etc.)
     */
    public function hydrate(string $class, Response $response): object;

    /**
     * Hydrates a raw data array into an instance of the given class.
     *
     * @template T of object
     * @param class-string<T>      $class The FQCN of the target DTO class.
     * @param array<string, mixed> $data  The raw data array to hydrate from.
     *
     * @return T
     *
     * @throws HydrationException
     */
    public function hydrateFromArray(string $class, array $data): object;

    /**
     * Hydrates a JSON string into an instance of the given class.
     *
     * @template T of object
     * @param class-string<T> $class The FQCN of the target DTO class.
     * @param string          $json  The raw JSON string.
     *
     * @return T
     *
     * @throws HydrationException
     */
    public function hydrateFromJson(string $class, string $json): object;
}

/**
 * HydrationException — Thrown When Hydration Fails
 */
final class HydrationException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $targetClass,
        public readonly string $fieldName = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
