<?php

declare(strict_types=1);

namespace Strapi\Database\Fields;

/** Port of packages/core/database/src/fields/number.ts. */
class NumberField extends Field
{
    public function toDB(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException("Expected a valid Number, got {$value}");
            }

            return $value;
        }

        if (is_bool($value)) {
            // JS Number(true) === 1 is never reached upstream (typeof boolean throws); keep that
            throw new \InvalidArgumentException('Expected a valid Number, got ' . ($value ? 'true' : 'false'));
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '' || !is_numeric($trimmed)) {
                throw new \InvalidArgumentException("Expected a valid Number, got {$value}");
            }

            return self::normalize($trimmed + 0);
        }

        throw new \InvalidArgumentException('Expected a valid Number, got ' . StringField::stringify($value));
    }

    public function fromDB(mixed $value): mixed
    {
        // lodash toNumber
        if (is_int($value) || is_float($value)) {
            return self::normalize($value);
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value === null) {
            return 0;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return 0;
            }

            return is_numeric($trimmed) ? self::normalize($trimmed + 0) : NAN;
        }

        return NAN;
    }

    private static function normalize(int|float $value): int|float
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            return (int) $value;
        }

        return $value;
    }
}
