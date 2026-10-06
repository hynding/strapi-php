<?php

declare(strict_types=1);

namespace Strapi\Database\Fields;

use Strapi\Database\Fields\Shared\Parsers;

/** Port of packages/core/database/src/fields/date.ts. */
class DateField extends Field
{
    public function toDB(mixed $value): mixed
    {
        return Parsers::parseDate($value);
    }

    public function fromDB(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $value;
    }
}
