<?php

declare(strict_types=1);

namespace Strapi\Database\Errors;

/** Port of packages/core/database/src/errors/invalid-datetime.ts. */
class InvalidDateTimeError extends DatabaseError
{
    public string $name = 'InvalidDatetimeFormat';

    public function __construct(string $message = 'Invalid relation format')
    {
        parent::__construct($message);
    }
}
