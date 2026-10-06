<?php

declare(strict_types=1);

namespace Strapi\Database\Fields;

use Strapi\Utils\Primitives\Strings;

/** Port of packages/core/database/src/fields/string.ts (lodash `toString` semantics). */
class StringField extends Field
{
    public function toDB(mixed $value): mixed
    {
        return self::stringify($value);
    }

    public function fromDB(mixed $value): mixed
    {
        return self::stringify($value);
    }

    public static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return implode(',', array_map(self::stringify(...), $value));
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::RFC7231);
        }
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        return Strings::stringify($value);
    }
}
