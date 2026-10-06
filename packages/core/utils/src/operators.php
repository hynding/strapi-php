<?php

declare(strict_types=1);

namespace Strapi\Utils;

/** Port of packages/core/utils/src/operators.ts. */
final class Operators
{
    public const GROUP_OPERATORS = ['$and', '$or'];

    public const WHERE_OPERATORS = [
        '$not',
        '$in',
        '$notIn',
        '$eq',
        '$eqi',
        '$ne',
        '$nei',
        '$gt',
        '$gte',
        '$lt',
        '$lte',
        '$null',
        '$notNull',
        '$between',
        '$startsWith',
        '$endsWith',
        '$startsWithi',
        '$endsWithi',
        '$contains',
        '$notContains',
        '$containsi',
        '$notContainsi',
        // Experimental, only for internal use
        '$jsonSupersetOf',
    ];

    public const CAST_OPERATORS = [
        '$not',
        '$in',
        '$notIn',
        '$eq',
        '$ne',
        '$gt',
        '$gte',
        '$lt',
        '$lte',
        '$between',
    ];

    public const ARRAY_OPERATORS = ['$in', '$notIn', '$between'];

    public const OPERATORS = [
        'where' => self::WHERE_OPERATORS,
        'cast' => self::CAST_OPERATORS,
        'group' => self::GROUP_OPERATORS,
        'array' => self::ARRAY_OPERATORS,
    ];

    /** @var array<string, list<string>>|null */
    private static ?array $lowercase = null;

    /** @return array<string, list<string>> */
    private static function lowercase(): array
    {
        return self::$lowercase ??= array_map(static fn (array $values): array => array_map('strtolower', $values), self::OPERATORS);
    }

    public static function isOperatorOfType(string $type, string $key, bool $ignoreCase = false): bool
    {
        if ($ignoreCase) {
            return in_array(strtolower($key), self::lowercase()[$type] ?? [], true);
        }

        return in_array($key, self::OPERATORS[$type] ?? [], true);
    }

    public static function isOperator(string $key, bool $ignoreCase = false): bool
    {
        foreach (array_keys(self::OPERATORS) as $type) {
            if (self::isOperatorOfType($type, $key, $ignoreCase)) {
                return true;
            }
        }

        return false;
    }
}
