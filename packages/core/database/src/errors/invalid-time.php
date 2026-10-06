<?php

declare(strict_types=1);

namespace Strapi\Database\Errors;

/** Port of packages/core/database/src/errors/invalid-time.ts. */
class InvalidTimeError extends DatabaseError
{
    public string $name = 'InvalidTimeFormat';

    public function __construct(string $message = 'Invalid time format, expected HH:mm:ss.SSS')
    {
        parent::__construct($message);
    }
}
