<?php

declare(strict_types=1);

namespace Strapi\Utils;

/** Port of packages/core/utils/src/parse-type.ts. */
final class ParseType
{
    private const TIME_REGEX = '/^(2[0-3]|[01][0-9]):([0-5][0-9]):([0-5][0-9])(\.[0-9]{1,3})?$/';

    private const TRUTHY_INPUTS = ['true', 't', '1', 1];
    private const FALSY_INPUTS = ['false', 'f', '0', 0];

    public static function parseTime(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s.v');
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException('Expected a string, got a ' . self::typeOf($value));
        }

        if (preg_match(self::TIME_REGEX, $value, $m) !== 1) {
            throw new \InvalidArgumentException('Invalid time format, expected HH:mm:ss.SSS');
        }

        $fraction = $m[4] ?? '.000';
        $fractionPart = str_pad(substr($fraction, 1), 3, '0');

        return "{$m[1]}:{$m[2]}:{$m[3]}.{$fractionPart}";
    }

    public static function parseDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException('Expected a string, got a ' . self::typeOf($value));
        }

        $date = self::parseISO($value);
        if ($date !== null) {
            return $date->format('Y-m-d');
        }

        throw new \InvalidArgumentException('Invalid format, expected an ISO compatible date');
    }

    public static function parseDateTimeOrTimestamp(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException('Expected a string, got a ' . self::typeOf($value));
        }

        $date = self::parseISO($value);
        if ($date !== null) {
            return $date;
        }

        // date-fns `parse(value, 'T', ...)`: milliseconds since the epoch
        if (preg_match('/^-?\d+$/', $value) === 1) {
            $ms = (int) $value;
            $seconds = intdiv($ms, 1000);
            $millis = abs($ms % 1000);
            $parsed = \DateTimeImmutable::createFromFormat('U.v', sprintf('%d.%03d', $seconds, $millis));
            if ($parsed !== false) {
                return $parsed;
            }
        }

        throw new \InvalidArgumentException('Invalid format, expected a timestamp or an ISO date');
    }

    /**
     * date-fns `parseISO`: accepts `YYYY-MM-DD`, `YYYY-MM-DDTHH:mm[:ss[.SSS]][Z|±HH:mm]` and the
     * space-separated variant; validates the calendar date (2019-02-31 is invalid).
     */
    public static function parseISO(string $value): ?\DateTimeImmutable
    {
        $pattern = '/^(\d{4})-(\d{2})(?:-(\d{2}))?(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,3})\d*)?)?\s*(Z|[+-]\d{2}:?\d{2})?)?$/i';
        if (preg_match($pattern, trim($value), $m) !== 1) {
            return null;
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 1;
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        $hour = (int) ($m[4] ?? 0);
        $minute = (int) ($m[5] ?? 0);
        $second = (int) ($m[6] ?? 0);
        if ($hour > 24 || $minute > 59 || $second > 59 || ($hour === 24 && ($minute > 0 || $second > 0))) {
            return null;
        }
        $millis = isset($m[7]) && $m[7] !== '' ? str_pad($m[7], 3, '0') : '000';
        $tz = isset($m[8]) && $m[8] !== '' ? strtoupper($m[8]) : null;

        $zone = $tz === null ? new \DateTimeZone(date_default_timezone_get()) : new \DateTimeZone($tz === 'Z' ? 'UTC' : $tz);

        $date = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s.v',
            sprintf('%04d-%02d-%02d %02d:%02d:%02d.%s', $year, $month, $day, $hour, $minute, $second, $millis),
            $zone,
        );

        return $date === false ? null : $date;
    }

    /** Whether `parseBoolean` would accept this value without `forceCast`. */
    public static function isBooleanLike(mixed $value): bool
    {
        if (is_bool($value)) {
            return true;
        }

        if (is_string($value) || is_int($value)) {
            return in_array($value, self::TRUTHY_INPUTS, true) || in_array($value, self::FALSY_INPUTS, true);
        }

        if (is_float($value)) {
            return $value === 1.0 || $value === 0.0;
        }

        return false;
    }

    public static function parseBoolean(mixed $value, bool $forceCast = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_float($value) && ($value === 1.0 || $value === 0.0)) {
            $value = (int) $value;
        }

        if (is_string($value) || is_int($value)) {
            if (in_array($value, self::TRUTHY_INPUTS, true)) {
                return true;
            }
            if (in_array($value, self::FALSY_INPUTS, true)) {
                return false;
            }
        }

        if ($forceCast) {
            return self::truthy($value);
        }

        throw new \InvalidArgumentException('Invalid boolean input. Expected "t","1","true","false","0","f"');
    }

    /** JavaScript `Boolean(value)`. */
    public static function truthy(mixed $value): bool
    {
        if ($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0) {
            return false;
        }
        if (is_float($value) && is_nan($value)) {
            return false;
        }

        return true;
    }

    /** JavaScript `Number(value)` / lodash `toNumber`. Returns NAN when not numeric. */
    public static function toNumber(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
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
            if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?$/i', $trimmed) === 1) {
                if (preg_match('/^[+-]?\d+$/', $trimmed) === 1 && abs((float) $trimmed) < PHP_INT_MAX) {
                    return (int) $trimmed;
                }

                return (float) $trimmed;
            }
            if (preg_match('/^[+-]?Infinity$/', $trimmed) === 1) {
                return str_starts_with($trimmed, '-') ? -INF : INF;
            }
            if (preg_match('/^0x[0-9a-f]+$/i', $trimmed) === 1) {
                return (int) hexdec(substr($trimmed, 2));
            }

            return NAN;
        }
        if (is_array($value)) {
            if ($value === []) {
                return 0;
            }
            if (count($value) === 1 && array_is_list($value)) {
                return self::toNumber($value[0]);
            }
        }

        return NAN;
    }

    /**
     * Cast basic values based on attribute type.
     *
     * @param array{type: string, value: mixed, forceCast?: bool} $options
     */
    public static function parseType(array $options): mixed
    {
        $type = $options['type'];
        $value = $options['value'];
        $forceCast = $options['forceCast'] ?? false;

        return match ($type) {
            'boolean' => self::parseBoolean($value, $forceCast),
            'integer', 'biginteger', 'float', 'decimal' => self::toNumber($value),
            'time' => self::parseTime($value),
            'date' => self::parseDate($value),
            'timestamp', 'datetime' => self::parseDateTimeOrTimestamp($value),
            default => $value,
        };
    }

    /** JavaScript `typeof` label for error messages. */
    public static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'object',
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_string($value) => 'string',
            is_callable($value) => 'function',
            default => 'object',
        };
    }
}
