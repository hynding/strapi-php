<?php

declare(strict_types=1);

namespace Strapi\Database\Fields;

use Strapi\Database\Fields\Shared\Parsers;

/** Port of packages/core/database/src/fields/timestamp.ts: read back as the epoch in milliseconds (string). */
class TimestampField extends Field
{
    public function toDB(mixed $value): mixed
    {
        return Parsers::parseDateTimeOrTimestamp($value);
    }

    public function fromDB(mixed $value): mixed
    {
        $date = Parsers::toDateTime($value);

        return $date === null ? null : (string) Parsers::toMilliseconds($date);
    }
}
