<?php

declare(strict_types=1);

namespace Strapi\Utils\Errors;

class PaginationError extends ApplicationError
{
    public string $name = 'PaginationError';

    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'Invalid pagination', array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $details, $previous);
    }
}
