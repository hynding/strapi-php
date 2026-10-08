<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\GraphqlScalars;

use GraphQL\Error\Error;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Type\Definition\CustomScalarType;

/**
 * graphql-scalars 1.22 `GraphQLDateTime` (iso-date/DateTime.ts). JS `Date` instances become
 * `DateTimeImmutable`s; parsed values are RFC 3339 strings in UTC with milliseconds (what a
 * `Date` serializes to), serialized values the same.
 */
final class DateTime
{
    private const string RFC_3339_REGEX = '/^(\d{4}-(0[1-9]|1[012])-(0[1-9]|[12][0-9]|3[01])T([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9]|60))(\.\d{1,})?(([Z])|([+|-]([01][0-9]|2[0-3]):[0-5][0-9]))$/';

    private static ?CustomScalarType $type = null;

    public static function type(): CustomScalarType
    {
        return self::$type ??= new CustomScalarType([
            'name' => 'DateTime',
            'description' => 'A date-time string at UTC, such as 2007-12-03T10:15:30Z, ' .
                'compliant with the `date-time` format outlined in section 5.6 of ' .
                'the RFC 3339 profile of the ISO 8601 standard for representation ' .
                'of dates and times using the Gregorian calendar.',
            'serialize' => [self::class, 'serialize'],
            'parseValue' => [self::class, 'parseValue'],
            'parseLiteral' => [self::class, 'parseLiteral'],
        ]);
    }

    public static function validateDateTime(string $value): bool
    {
        $value = strtoupper($value);
        if (preg_match(self::RFC_3339_REGEX, $value) !== 1) {
            return false;
        }

        $index = (int) strpos($value, 'T');

        return Date::validateDate(substr($value, 0, $index)) && self::toDate($value) !== null;
    }

    private static function toDate(string $value): ?\DateTimeImmutable
    {
        // keep milliseconds only, like `new Date()`
        $normalized = preg_replace_callback('/\.(\d+)/', static fn (array $m): string => '.' . str_pad(substr($m[1], 0, 3), 3, '0'), $value) ?? $value;
        try {
            return new \DateTimeImmutable($normalized);
        } catch (\Exception) {
            return null;
        }
    }

    /** `Date.prototype.toISOString()` */
    public static function toISOString(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function serialize(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return self::toISOString($value);
        }

        if (is_string($value)) {
            if (self::validateDateTime($value)) {
                return self::toISOString(self::toDate(strtoupper($value)) ?? new \DateTimeImmutable('@0'));
            }

            // database drivers may return `YYYY-MM-DD HH:mm:ss(.SSS)` (no `T`, UTC)
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d+)?$/', $value) === 1) {
                $date = self::toDate(str_replace(' ', 'T', $value) . 'Z');
                if ($date !== null) {
                    return self::toISOString($date);
                }
            }

            throw new Error("DateTime cannot represent an invalid date-time-string {$value}.");
        }

        if (is_int($value) || is_float($value)) {
            $date = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.3F', $value / 1000));
            if ($date === false) {
                throw new Error('DateTime cannot represent an invalid Unix timestamp ' . $value);
            }

            return self::toISOString($date);
        }

        throw new Error('DateTime cannot be serialized from a non string, non numeric or non Date type ' . json_encode($value));
    }

    public static function parseValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return self::toISOString($value);
        }

        if (is_string($value)) {
            if (self::validateDateTime($value)) {
                return self::toISOString(self::toDate(strtoupper($value)) ?? new \DateTimeImmutable('@0'));
            }

            throw new Error("DateTime cannot represent an invalid date-time-string {$value}.");
        }

        throw new Error('DateTime cannot represent non string or Date type ' . json_encode($value));
    }

    /** @param array<string, mixed>|null $variables */
    public static function parseLiteral(Node $ast, ?array $variables = null): string
    {
        if (!$ast instanceof StringValueNode) {
            $value = property_exists($ast, 'value') ? (string) $ast->value : 'false';

            throw new Error("DateTime cannot represent non string or Date type {$value}", $ast);
        }

        $value = $ast->value;
        if (self::validateDateTime($value)) {
            return self::toISOString(self::toDate(strtoupper($value)) ?? new \DateTimeImmutable('@0'));
        }

        throw new Error("DateTime cannot represent an invalid date-time-string {$value}.", $ast);
    }
}
