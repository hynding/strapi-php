<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\GraphqlScalars;

use GraphQL\Error\Error;
use GraphQL\Language\AST\Node;
use GraphQL\Language\Printer;
use GraphQL\Type\Definition\CustomScalarType;

/**
 * graphql-scalars 1.22 `GraphQLLong` (`GraphQLBigInt` renamed). JS `BigInt`s are PHP ints, or
 * numeric strings beyond PHP_INT_MAX; values outside the JS safe-integer range serialize as
 * strings (BigInt without a `toJSON` patch).
 */
final class Long
{
    private const int MAX_SAFE_INTEGER = 9007199254740991;

    private static ?CustomScalarType $type = null;

    public static function type(): CustomScalarType
    {
        return self::$type ??= new CustomScalarType([
            'name' => 'Long',
            'description' => 'The `BigInt` scalar type represents non-fractional signed whole numeric values.',
            'serialize' => [self::class, 'serialize'],
            'parseValue' => [self::class, 'parseValue'],
            'parseLiteral' => [self::class, 'parseLiteral'],
        ]);
    }

    private static function toBigInt(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^[+-]?\d+$/', $value) !== 1) {
            return null;
        }
        $negative = $value[0] === '-';
        $digits = ltrim($value, '+-');
        $digits = ltrim($digits, '0');
        $digits = $digits === '' ? '0' : $digits;

        return ($negative && $digits !== '0' ? '-' : '') . $digits;
    }

    private static function serializeSafeBigInt(string $value): int|string
    {
        $abs = ltrim($value, '-');
        if (strlen($abs) < 16 || (strlen($abs) === 16 && strcmp($abs, (string) self::MAX_SAFE_INTEGER) <= 0)) {
            return (int) $value;
        }

        return $value;
    }

    public static function serialize(mixed $outputValue): int|string
    {
        if (is_bool($outputValue)) {
            return $outputValue ? 1 : 0;
        }

        if (is_int($outputValue)) {
            return self::serializeSafeBigInt((string) $outputValue);
        }

        if (is_float($outputValue)) {
            if (floor($outputValue) !== $outputValue || is_infinite($outputValue)) {
                throw new Error("BigInt cannot represent non-integer value: {$outputValue}");
            }

            return self::serializeSafeBigInt(number_format($outputValue, 0, '.', ''));
        }

        if (is_string($outputValue) && $outputValue !== '') {
            $bigint = self::toBigInt($outputValue);
            if ($bigint === null || $bigint !== $outputValue) {
                throw new Error("BigInt cannot represent non-integer value: {$outputValue}");
            }

            return self::serializeSafeBigInt($bigint);
        }

        throw new Error('BigInt cannot represent non-integer value: ' . (is_scalar($outputValue) ? (string) $outputValue : json_encode($outputValue)));
    }

    public static function parseValue(mixed $inputValue): int|string
    {
        $string = is_bool($inputValue) ? ($inputValue ? 'true' : 'false') : (is_scalar($inputValue) ? (string) $inputValue : '');
        if (is_float($inputValue) && floor($inputValue) === $inputValue) {
            $string = number_format($inputValue, 0, '.', '');
        }
        $bigint = self::toBigInt($string);
        if ($bigint === null || $bigint !== $string) {
            throw new Error("BigInt cannot represent value: {$string}");
        }

        return self::toPhp($bigint);
    }

    /** @param array<string, mixed>|null $variables */
    public static function parseLiteral(Node $valueNode, ?array $variables = null): int|string
    {
        if (!property_exists($valueNode, 'value')) {
            throw new Error('BigInt cannot represent non-integer value: ' . Printer::doPrint($valueNode), $valueNode);
        }

        $value = $valueNode->value;
        $string = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        $bigint = self::toBigInt($string);
        if ($bigint === null || $bigint !== $string) {
            throw new Error("BigInt cannot represent value: {$string}");
        }

        return self::toPhp($bigint);
    }

    private static function toPhp(string $bigint): int|string
    {
        $int = (int) $bigint;

        return (string) $int === $bigint ? $int : $bigint;
    }
}
