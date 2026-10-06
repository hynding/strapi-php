<?php

declare(strict_types=1);

namespace Strapi\Utils\Errors;

class ForbiddenError extends ApplicationError
{
    public string $name = 'ForbiddenError';

    public int $status = 403;

    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'Forbidden access', array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $details, $previous);
    }
}
