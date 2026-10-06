<?php

declare(strict_types=1);

namespace Strapi\Database\Fields;

use Strapi\Database\Fields\Shared\Parsers;

/** Port of packages/core/database/src/fields/time.ts. */
class TimeField extends Field
{
    public function toDB(mixed $value): mixed
    {
        return Parsers::parseTime($value);
    }

    public function fromDB(mixed $value): mixed
    {
        return $value;
    }
}
