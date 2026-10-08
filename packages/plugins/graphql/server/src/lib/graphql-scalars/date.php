<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\GraphqlScalars;

use GraphQL\Error\Error;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\StringValueNode;
use GraphQL\Type\Definition\CustomScalarType;

/**
 * graphql-scalars 1.22 `GraphQLDate` (iso-date/Date.ts). `parseDate()` builds a JS `Date`; the
 * plugin casts it back to the `YYYY-MM-DD` string (services/internals/scalars/date.ts), which is
 * what `parseValue` / `parseLiteral` return here directly.
 */
final class Date
{
    private const string RFC_3339_REGEX = '/^(\d{4}-(0[1-9]|1[012])-(0[1-9]|[12][0-9]|3[01]))$/';

    private static ?CustomScalarType $type = null;

    public static function type(): CustomScalarType
    {
        return self::$type ??= new CustomScalarType([
            'name' => 'Date',
            'description' => 'A date string, such as 2007-12-03, compliant with the `full-date` ' .
                'format outlined in section 5.6 of the RFC 3339 profile of the ' .
                'ISO 8601 standard for representation of dates and times using ' .
                'the Gregorian calendar.',
            'serialize' => [self::class, 'serialize'],
            'parseValue' => [self::class, 'parseValue'],
            'parseLiteral' => [self::class, 'parseLiteral'],
        ]);
    }

    private static function leapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    public static function validateDate(string $datestring): bool
    {
        if (preg_match(self::RFC_3339_REGEX, $datestring) !== 1) {
            return false;
        }

        $year = (int) substr($datestring, 0, 4);
        $month = (int) substr($datestring, 5, 2);
        $day = (int) substr($datestring, 8, 2);

        switch ($month) {
            case 2: // February
                if (self::leapYear($year) && $day > 29) {
                    return false;
                }
                if (!self::leapYear($year) && $day > 28) {
                    return false;
                }

                return true;
            case 4: // April
            case 6: // June
            case 9: // September
            case 11: // November
                if ($day > 30) {
                    return false;
                }
                break;
        }

        return true;
    }

    public static function serialize(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        }

        if (is_string($value)) {
            if (self::validateDate($value)) {
                return $value;
            }

            throw new Error("Date cannot represent an invalid date-string {$value}.");
        }

        throw new Error('Date cannot represent a non string, or non Date type ' . json_encode($value));
    }

    public static function parseValue(mixed $value): string
    {
        if (!is_string($value)) {
            throw new Error('Date cannot represent non string type ' . json_encode($value));
        }

        if (self::validateDate($value)) {
            return $value;
        }

        throw new Error("Date cannot represent an invalid date-string {$value}.");
    }

    /** @param array<string, mixed>|null $variables */
    public static function parseLiteral(Node $ast, ?array $variables = null): string
    {
        if (!$ast instanceof StringValueNode) {
            $value = property_exists($ast, 'value') ? (string) $ast->value : 'false';

            throw new Error("Date cannot represent non string type {$value}", $ast);
        }

        $value = $ast->value;
        if (self::validateDate($value)) {
            return $value;
        }

        throw new Error("Date cannot represent an invalid date-string {$value}.", $ast);
    }
}
