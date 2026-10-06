<?php

declare(strict_types=1);

namespace Strapi\Database\Errors;

use Strapi\Utils\Errors\ApplicationError;

/** Port of packages/core/database/src/errors/database.ts. */
class DatabaseError extends ApplicationError
{
    public string $name = 'DatabaseError';

    public int $status = 500;

    /** @param array<string, mixed> $details */
    public function __construct(string $message = 'A database error occurred', array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $details, $previous);
    }
}
