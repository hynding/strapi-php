<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Types\Schema\Schema;

/** Port of packages/core/utils/src/relations.ts. */
final class Relations
{
    public const MANY_RELATIONS = ['oneToMany', 'manyToMany'];

    public const CONSTANTS = ['MANY_RELATIONS' => self::MANY_RELATIONS];

    /**
     * @param Schema|array<string, mixed> $contentType
     * @return list<string>
     */
    public static function getRelationalFields(Schema|array $contentType): array
    {
        return ContentTypes::getRelationalAttributes($contentType);
    }

    /** @param array<string, mixed> $attribute */
    public static function isOneToAny(array $attribute): bool
    {
        return ContentTypes::isRelationalAttribute($attribute) && in_array($attribute['relation'] ?? null, ['oneToOne', 'oneToMany'], true);
    }

    /** @param array<string, mixed> $attribute */
    public static function isManyToAny(array $attribute): bool
    {
        return ContentTypes::isRelationalAttribute($attribute) && in_array($attribute['relation'] ?? null, ['manyToMany', 'manyToOne'], true);
    }

    /** @param array<string, mixed> $attribute */
    public static function isAnyToOne(array $attribute): bool
    {
        return ContentTypes::isRelationalAttribute($attribute) && in_array($attribute['relation'] ?? null, ['oneToOne', 'manyToOne'], true);
    }

    /** @param array<string, mixed> $attribute */
    public static function isAnyToMany(array $attribute): bool
    {
        return ContentTypes::isRelationalAttribute($attribute) && in_array($attribute['relation'] ?? null, ['oneToMany', 'manyToMany'], true);
    }

    /** @param array<string, mixed> $attribute */
    public static function isPolymorphic(array $attribute): bool
    {
        return in_array($attribute['relation'] ?? null, ['morphOne', 'morphMany', 'morphToOne', 'morphToMany'], true);
    }

    /**
     * Valid keys in the `options` property of relation reordering, each with its value validator.
     *
     * @return array<string, callable(mixed): bool>
     */
    public static function validRelationOrderingKeys(): array
    {
        return ['strict' => static fn (mixed $value): bool => is_bool($value)];
    }
}
