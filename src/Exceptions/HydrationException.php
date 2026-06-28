<?php

declare(strict_types=1);

namespace WireHttp\Exceptions;

class HydrationException extends WireHttpException
{
    public function __construct(
        string $message,
        public readonly ?string $targetClass = null,
        public readonly ?string $fieldName = null,
        public readonly mixed $value = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
