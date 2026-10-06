<?php

declare(strict_types=1);

namespace Strapi\Utils\Errors;

class NotFoundError extends ApplicationError
{
    public string $name = 'NotFoundError';

    public int $status = 404;

    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'Entity not found', array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $details, $previous);
    }
}
