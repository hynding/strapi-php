<?php

declare(strict_types=1);

namespace Strapi\Database\Fields;

use Strapi\Database\Fields\Shared\Parsers;

/**
 * Port of packages/core/database/src/fields/datetime.ts.
 *
 * toDB() returns a DateTimeImmutable; the dialect serialises it for the driver (epoch
 * milliseconds on SQLite, like Knex, `Y-m-d H:i:s.u` UTC elsewhere). fromDB() returns the ISO
 * string upstream's `toISOString()` produces.
 */
class DatetimeField extends Field
{
    public function toDB(mixed $value): mixed
    {
        return Parsers::parseDateTimeOrTimestamp($value);
    }

    public function fromDB(mixed $value): mixed
    {
        $date = Parsers::toDateTime($value);

        return $date === null ? null : Parsers::toIsoString($date);
    }
}
