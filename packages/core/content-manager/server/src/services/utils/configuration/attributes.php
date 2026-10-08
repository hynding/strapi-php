<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services\Utils\Configuration;

use Strapi\Core\Strapi;
use Strapi\Utils\ContentTypes;

/**
 * Port of server/src/services/utils/configuration/attributes.ts.
 *
 * `$schema` is a content-manager model (data-mapper's `toContentManagerModel`, an array).
 * `getSortableAttributes` reads `strapi.getModel`, so it takes the Strapi instance.
 */
final class Attributes
{
    private const array NON_SORTABLES = ['component', 'json', 'media', 'richtext', 'dynamiczone', 'blocks'];
    private const array SORTABLE_RELATIONS = ['oneToOne', 'manyToOne'];

    private const array NON_LISTABLES = ['json', 'password', 'richtext', 'dynamiczone', 'blocks'];
    private const array LISTABLE_RELATIONS = ['oneToOne', 'oneToMany', 'manyToOne', 'manyToMany'];

    /** @param array<string, mixed> $schema */
    private static function hasAttribute(array $schema, mixed $name): bool
    {
        return (is_string($name) || is_int($name)) && is_array($schema['attributes'] ?? null) && array_key_exists($name, $schema['attributes']);
    }

    /**
     * hidden fields are fields that are configured to be hidden from list, and edit views
     *
     * @param array<string, mixed> $schema
     */
    public static function isHidden(array $schema, mixed $name): bool
    {
        if (!self::hasAttribute($schema, $name)) {
            return false;
        }

        return ($schema['config']['attributes'][$name]['hidden'] ?? false) === true;
    }

    /** @param array<string, mixed> $schema */
    public static function isListable(array $schema, mixed $name): bool
    {
        if (!self::hasAttribute($schema, $name)) {
            return false;
        }

        if (self::isHidden($schema, $name)) {
            return false;
        }

        $attribute = $schema['attributes'][$name];
        if (in_array($attribute['type'] ?? null, self::NON_LISTABLES, true)) {
            return false;
        }

        if (self::isRelation($attribute) && !in_array($attribute['relationType'] ?? null, self::LISTABLE_RELATIONS, true)) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $schema */
    public static function isSortable(array $schema, mixed $name): bool
    {
        if (!self::hasAttribute($schema, $name)) {
            return false;
        }

        if (($schema['modelType'] ?? null) === 'component' && $name === 'id') {
            return false;
        }

        $attribute = $schema['attributes'][$name];
        if (in_array($attribute['type'] ?? null, self::NON_SORTABLES, true)) {
            return false;
        }

        if (self::isRelation($attribute) && !in_array($attribute['relationType'] ?? null, self::SORTABLE_RELATIONS, true)) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $schema */
    public static function isSearchable(array $schema, mixed $name): bool
    {
        return self::isSortable($schema, $name);
    }

    /** @param array<string, mixed> $schema */
    public static function isVisible(array $schema, mixed $name): bool
    {
        if (!self::hasAttribute($schema, $name)) {
            return false;
        }

        if (self::isHidden($schema, $name)) {
            return false;
        }

        if (self::isTimestamp($schema, $name) || $name === 'id' || $name === 'documentId') {
            return false;
        }

        if (self::isPublicationField($name)) {
            return false;
        }

        if (self::isCreatorField($schema, $name)) {
            return false;
        }

        return true;
    }

    private static function isPublicationField(mixed $name): bool
    {
        return ContentTypes::PUBLISHED_AT_ATTRIBUTE === $name;
    }

    /** @param array<string, mixed> $schema */
    private static function isTimestamp(array $schema, mixed $name): bool
    {
        if (!self::hasAttribute($schema, $name)) {
            return false;
        }

        return in_array($name, ContentTypes::getTimestamps($schema), true);
    }

    /** @param array<string, mixed> $schema */
    private static function isCreatorField(array $schema, mixed $name): bool
    {
        if (!self::hasAttribute($schema, $name)) {
            return false;
        }

        return in_array($name, ContentTypes::getCreatorFields($schema), true);
    }

    public static function isRelation(mixed $attribute): bool
    {
        return is_array($attribute) && ($attribute['type'] ?? null) === 'relation';
    }

    /** @param array<string, mixed> $schema */
    public static function hasRelationAttribute(array $schema, mixed $name): bool
    {
        if (!self::hasAttribute($schema, $name)) {
            return false;
        }

        if (self::isHidden($schema, $name)) {
            return false;
        }

        if (!self::isVisible($schema, $name)) {
            return false;
        }

        return self::isRelation($schema['attributes'][$name]);
    }

    /** @param array<string, mixed> $schema */
    public static function hasEditableAttribute(array $schema, mixed $name): bool
    {
        if (!self::hasAttribute($schema, $name)) {
            return false;
        }

        if (self::isHidden($schema, $name)) {
            return false;
        }

        if (!self::isVisible($schema, $name)) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $schema */
    private static function findFirstStringAttribute(array $schema): ?string
    {
        foreach (is_array($schema['attributes'] ?? null) ? $schema['attributes'] : [] as $key => $attribute) {
            if (($attribute['type'] ?? null) === 'string' && $key !== 'id') {
                return (string) $key;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $schema */
    public static function getDefaultMainField(array $schema): string
    {
        return self::findFirstStringAttribute($schema) ?? 'id';
    }

    /**
     * Returns list of all sortable attributes for a given content type schema
     * TODO V5: Refactor non visible fields to be a part of content-manager schema so we can use isSortable instead
     *
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    public static function getSortableAttributes(Strapi $strapi, array $schema): array
    {
        $attributes = is_array($schema['attributes'] ?? null) ? $schema['attributes'] : [];
        $validAttributes = array_values(array_filter(
            array_map('strval', array_keys($attributes)),
            static fn (string $key): bool => self::isListable($schema, $key),
        ));

        $model = $strapi->getModel((string) ($schema['uid'] ?? ''));
        $nonVisibleWritableAttributes = $model === null ? [] : array_values(array_intersect(
            ContentTypes::getNonVisibleAttributes($model),
            ContentTypes::getWritableAttributes($model),
        ));

        $identifierField = array_key_exists('documentId', $attributes) ? 'documentId' : 'id';

        return [
            $identifierField,
            ...$validAttributes,
            ...$nonVisibleWritableAttributes,
            ContentTypes::CREATED_BY_ATTRIBUTE,
            ContentTypes::UPDATED_BY_ATTRIBUTE,
        ];
    }
}
