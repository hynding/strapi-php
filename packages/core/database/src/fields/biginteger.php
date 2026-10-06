<?php

declare(strict_types=1);

namespace Strapi\Database\Fields;

/** Port of packages/core/database/src/fields/biginteger.ts: big integers travel as strings. */
class BigIntegerField extends Field
{
    private const BIG_INTEGER_REGEX = '/^[+-]?\d+$/';

    public function toDB(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return self::toBigIntegerString($value);
    }

    public function fromDB(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        try {
            return self::toBigIntegerString($value);
        } catch (\InvalidArgumentException) {
            // Preserve backward compatibility for legacy rows with malformed bigint values.
            return StringField::stringify($value);
        }
    }

    private static function toBigIntegerString(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (!is_finite($value) || floor($value) !== $value) {
                throw new \InvalidArgumentException("Expected a valid BigInteger, got {$value}");
            }

            return number_format($value, 0, '', '');
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if (preg_match(self::BIG_INTEGER_REGEX, $trimmed) !== 1) {
                throw new \InvalidArgumentException("Expected a valid BigInteger, got {$value}");
            }

            // BigInt(trimmed).toString(): normalise the sign and leading zeros
            $negative = str_starts_with($trimmed, '-');
            $digits = ltrim(ltrim($trimmed, '+-'), '0');
            if ($digits === '') {
                return '0';
            }

            return ($negative ? '-' : '') . $digits;
        }

        throw new \InvalidArgumentException('Expected a valid BigInteger, got ' . StringField::stringify($value));
    }
}
