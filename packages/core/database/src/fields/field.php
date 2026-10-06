<?php

declare(strict_types=1);

namespace Strapi\Database\Fields;

/** Port of packages/core/database/src/fields/field.ts. */
class Field
{
    /** @param array<string, mixed> $config */
    public function __construct(public array $config = [])
    {
    }

    public function toDB(mixed $value): mixed
    {
        return $value;
    }

    public function fromDB(mixed $value): mixed
    {
        return $value;
    }
}
