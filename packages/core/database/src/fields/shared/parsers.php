<?php

declare(strict_types=1);

namespace Strapi\Database\Fields\Shared;

use Strapi\Database\Errors\InvalidDateError;
use Strapi\Database\Errors\InvalidDateTimeError;
use Strapi\Database\Errors\InvalidTimeError;
use Strapi\Utils\ParseType;

/** Port of packages/core/database/src/fields/shared/parsers.ts. */
final class Parsers
{
    private const DATE_REGEX = '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])$/';
    private const PARTIAL_DATE_REGEX = '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])/';
    private const TIME_REGEX = '/^(2[0-3]|[01][0-9]):([0-5][0-9]):([0-5][0-9])(\.[0-9]{1,3})?$/';

    /**
     * Accepts a DateTime, an ISO 8601 string or a millisecond epoch (number or numeric string),
     * exactly like upstream (date-fns parseISO / parse 'T').
     */
    public static function parseDateTimeOrTimestamp(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }

        if (is_int($value) || is_float($value)) {
            return self::fromMilliseconds((int) $value);
        }

        $string = self::stringify($value);

        $date = ParseType::parseISO($string);
        if ($date !== null) {
            return $date;
        }

        if (preg_match('/^-?\d+$/', trim($string)) === 1) {
            return self::fromMilliseconds((int) trim($string));
        }

        throw new InvalidDateTimeError('Invalid format, expected a timestamp or an ISO date');
    }

    public static function parseDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $string = is_string($value) ? $value : null;
        $extractedValue = null;
        if ($string !== null && preg_match(self::PARTIAL_DATE_REGEX, $string, $m) === 1) {
            $extractedValue = $m[0];
        }

        if ($extractedValue !== null && preg_match(self::DATE_REGEX, $string ?? '') !== 1) {
            // TODO V5: throw an error when format yyyy-MM-dd is not respected
            trigger_error(
                "[deprecated] Using a date format other than YYYY-MM-DD will be removed in future versions. Date received: {$string}. Date stored: {$extractedValue}.",
                E_USER_DEPRECATED,
            );
        }

        if ($extractedValue === null) {
            throw new InvalidDateError('Invalid format, expected yyyy-MM-dd');
        }

        if (ParseType::parseISO($extractedValue) === null) {
            throw new InvalidDateError('Invalid date');
        }

        return $extractedValue;
    }

    public static function parseTime(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s.v');
        }

        if (!is_string($value)) {
            throw new InvalidTimeError('Expected a string, got a ' . ParseType::typeOf($value));
        }

        if (preg_match(self::TIME_REGEX, $value, $m) !== 1) {
            throw new InvalidTimeError('Invalid time format, expected HH:mm:ss.SSS');
        }

        $fraction = $m[4] ?? '.000';
        $fractionPart = str_pad(substr($fraction, 1), 3, '0');

        return "{$m[1]}:{$m[2]}:{$m[3]}.{$fractionPart}";
    }

    /**
     * Lenient conversion used when reading from the database (upstream `new Date(value)`):
     * millisecond epochs (SQLite, as Knex stores Dates there), `Y-m-d H:i:s[.u]` strings (MySQL,
     * PostgreSQL — treated as UTC) and ISO strings. Returns null when the value is not a date.
     */
    public static function toDateTime(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (is_int($value) || is_float($value)) {
            return self::fromMilliseconds((int) $value);
        }
        if (!is_string($value)) {
            return null;
        }

        $string = trim($value);
        if ($string === '') {
            return null;
        }
        if (preg_match('/^-?\d+$/', $string) === 1) {
            return self::fromMilliseconds((int) $string);
        }

        // Database strings carry no zone: they were written as UTC by toDatabaseValue().
        if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d+)?)?$/', $string) === 1) {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', self::normalizeDbDateString($string), new \DateTimeZone('UTC'));

            return $date === false ? null : $date;
        }

        return ParseType::parseISO($string);
    }

    /** `Y-m-d H:i:s.u` string in UTC, the storage format for MySQL and PostgreSQL. */
    public static function toDatabaseString(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    /** `toISOString()`: UTC with millisecond precision. */
    public static function toIsoString(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function toMilliseconds(\DateTimeInterface $date): int
    {
        return (int) $date->format('U') * 1000 + intdiv((int) $date->format('u'), 1000);
    }

    public static function fromMilliseconds(int $ms): \DateTimeImmutable
    {
        $seconds = intdiv($ms, 1000);
        $millis = $ms % 1000;
        if ($millis < 0) {
            $millis += 1000;
            $seconds -= 1;
        }

        $date = \DateTimeImmutable::createFromFormat('U.v', sprintf('%d.%03d', $seconds, $millis), new \DateTimeZone('UTC'));
        if ($date === false) {
            throw new InvalidDateTimeError('Invalid format, expected a timestamp or an ISO date');
        }

        return $date;
    }

    private static function normalizeDbDateString(string $value): string
    {
        $value = str_replace('T', ' ', $value);
        if (!str_contains($value, '.')) {
            if (substr_count($value, ':') === 1) {
                $value .= ':00';
            }
            $value .= '.000000';
        } else {
            [$main, $fraction] = explode('.', $value, 2);
            $value = $main . '.' . substr(str_pad($fraction, 6, '0'), 0, 6);
        }

        return $value;
    }

    private static function stringify(mixed $value): string
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
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
