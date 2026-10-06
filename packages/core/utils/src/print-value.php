<?php

declare(strict_types=1);

namespace Strapi\Utils;

/** Port of packages/core/utils/src/print-value.ts (copied by upstream from yup). */
final class PrintValue
{
    private static function printNumber(int|float $val): string
    {
        if (is_float($val) && is_nan($val)) {
            return 'NaN';
        }
        if (is_float($val) && $val === 0.0 && (1 / $val) < 0) {
            return '-0';
        }
        if (is_float($val) && is_infinite($val)) {
            return $val > 0 ? 'Infinity' : '-Infinity';
        }
        if (is_float($val) && floor($val) === $val && abs($val) < 1e21) {
            return (string) (int) $val;
        }

        return (string) $val;
    }

    private static function printSimpleValue(mixed $val, bool $quoteStrings = false): ?string
    {
        if ($val === null) {
            return 'null';
        }
        if ($val === true) {
            return 'true';
        }
        if ($val === false) {
            return 'false';
        }
        if (is_int($val) || is_float($val)) {
            return self::printNumber($val);
        }
        if (is_string($val)) {
            return $quoteStrings ? "\"{$val}\"" : $val;
        }
        if ($val instanceof \Closure) {
            return '[Function anonymous]';
        }
        if ($val instanceof \DateTimeInterface) {
            return $val->format('Y-m-d\TH:i:s.v\Z');
        }
        if ($val instanceof \Throwable) {
            return '[' . (new \ReflectionClass($val))->getShortName() . ': ' . $val->getMessage() . ']';
        }

        return null;
    }

    public static function printValue(mixed $value, bool $quoteStrings = false): string
    {
        $result = self::printSimpleValue($value, $quoteStrings);
        if ($result !== null) {
            return $result;
        }

        $encoded = json_encode(self::replace($value, $quoteStrings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '' : $encoded;
    }

    private static function replace(mixed $value, bool $quoteStrings): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                // like yup's replacer: JSON-native scalars stay as they are, only dates/errors/closures are stringified
                $out[$k] = match (true) {
                    is_array($v), $v instanceof \JsonSerializable => self::replace($v, $quoteStrings),
                    $v === null, is_bool($v), is_int($v), is_string($v) => $v,
                    is_float($v) && is_finite($v) => $v,
                    default => self::printSimpleValue($v, $quoteStrings) ?? self::replace($v, $quoteStrings),
                };
            }

            return $value === [] ? new \stdClass() : $out;
        }
        if ($value instanceof \JsonSerializable) {
            return self::replace($value->jsonSerialize(), $quoteStrings);
        }
        if (is_object($value)) {
            return self::replace(get_object_vars($value), $quoteStrings) ?: new \stdClass();
        }

        return $value;
    }
}
