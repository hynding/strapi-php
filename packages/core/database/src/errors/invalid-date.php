<?php

declare(strict_types=1);

namespace Strapi\Database\Errors;

/** Port of packages/core/database/src/errors/invalid-date.ts. */
class InvalidDateError extends DatabaseError
{
    public string $name = 'InvalidDateFormat';

    public function __construct(string $message = 'Invalid date format, expected YYYY-MM-DD')
    {
        parent::__construct($message);
    }
}
