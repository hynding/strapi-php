<?php

declare(strict_types=1);

namespace Strapi\Database\Errors;

/** Port of packages/core/database/src/errors/not-null.ts. */
class NotNullError extends DatabaseError
{
    public string $name = 'NotNullError';

    public function __construct(string $column = '', ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('Not null constraint violation%s.', $column !== '' ? " on column {$column}" : ''), ['column' => $column], $previous);
    }
}
