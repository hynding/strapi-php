<?php

declare(strict_types=1);

namespace Strapi\Database\Utils;

/** Port of packages/core/database/src/utils/types.ts. */
final class Types
{
    public const SCALAR_TYPES = [
        'increments',
        'password',
        'email',
        'string',
        'uid',
        'richtext',
        'text',
        'json',
        'enumeration',
        'integer',
        'biginteger',
        'float',
        'decimal',
        'date',
        'time',
        'datetime',
        'timestamp',
        'boolean',
        'blocks',
    ];

    public const STRING_TYPES = ['string', 'text', 'uid', 'email', 'enumeration', 'richtext'];
    public const NUMBER_TYPES = ['biginteger', 'integer', 'decimal', 'float'];

    public static function isString(string $type): bool
    {
        return in_array($type, self::STRING_TYPES, true);
    }

    public static function isNumber(string $type): bool
    {
        return in_array($type, self::NUMBER_TYPES, true);
    }

    public static function isScalar(string $type): bool
    {
        return in_array($type, self::SCALAR_TYPES, true);
    }

    public static function isRelation(string $type): bool
    {
        return $type === 'relation';
    }

    /** @param array<string, mixed> $attribute */
    public static function isScalarAttribute(array $attribute): bool
    {
        return self::isScalar((string) ($attribute['type'] ?? ''));
    }

    /** @param array<string, mixed> $attribute */
    public static function isRelationalAttribute(array $attribute): bool
    {
        return self::isRelation((string) ($attribute['type'] ?? ''));
    }
}
