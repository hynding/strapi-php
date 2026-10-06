<?php

declare(strict_types=1);

namespace Strapi\Database\Fields;

/** Port of packages/core/database/src/fields/boolean.ts. */
class BooleanField extends Field
{
    public function toDB(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        if ((is_string($value) || is_int($value)) && in_array($value, ['true', 't', '1', 1], true)) {
            return true;
        }

        if ((is_string($value) || is_int($value)) && in_array($value, ['false', 'f', '0', 0], true)) {
            return false;
        }

        return (bool) $value;
    }

    public function fromDB(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        $strVal = StringField::stringify($value);

        if ($strVal === '1') {
            return true;
        }
        if ($strVal === '0') {
            return false;
        }

        return null;
    }
}
