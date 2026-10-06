<?php

declare(strict_types=1);

namespace Strapi\Database\Errors;

/** Port of packages/core/database/src/errors/invalid-relation.ts. */
class InvalidRelationError extends DatabaseError
{
    public string $name = 'InvalidRelationFormat';

    public function __construct(string $message = 'Invalid relation format')
    {
        parent::__construct($message);
    }
}
