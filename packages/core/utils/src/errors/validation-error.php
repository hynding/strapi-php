<?php

declare(strict_types=1);

namespace Strapi\Utils\Errors;

class ValidationError extends ApplicationError
{
    public string $name = 'ValidationError';

    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'Validation error', array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $details, $previous);
    }
}
