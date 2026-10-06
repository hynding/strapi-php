<?php

declare(strict_types=1);

namespace Strapi\Utils\Errors;

class UnauthorizedError extends ApplicationError
{
    public string $name = 'UnauthorizedError';

    public int $status = 401;

    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'Unauthorized', array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $details, $previous);
    }
}
