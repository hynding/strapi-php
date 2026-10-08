<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Utils;

use Strapi\Plugin\Graphql\Services\Constants;

/**
 * Port of server/src/services/utils/attributes.ts. Attributes are schema attribute arrays
 * (`['type' => 'relation', 'relation' => 'oneToMany', ...]`).
 */
final class Attributes
{
    /**
     * Check if the given attribute is a Strapi scalar
     *
     * @param array<string, mixed> $attribute
     */
    public function isStrapiScalar(array $attribute): bool
    {
        return in_array($attribute['type'] ?? null, Constants::STRAPI_SCALARS, true);
    }

    /**
     * Check if the given attribute is a GraphQL scalar
     *
     * @param array<string, mixed> $attribute
     */
    public function isGraphQLScalar(array $attribute): bool
    {
        return in_array($attribute['type'] ?? null, Constants::GRAPHQL_SCALARS, true);
    }

    /**
     * Check if the given attribute is a polymorphic relation
     *
     * @param array<string, mixed> $attribute
     */
    public function isMorphRelation(array $attribute): bool
    {
        return ($attribute['type'] ?? null) === 'relation' && str_contains((string) ($attribute['relation'] ?? ''), 'morph');
    }

    /**
     * Check if the given attribute is a media
     *
     * @param array<string, mixed> $attribute
     */
    public function isMedia(array $attribute): bool
    {
        return ($attribute['type'] ?? null) === 'media';
    }

    /**
     * Check if the given attribute is a relation
     *
     * @param array<string, mixed> $attribute
     */
    public function isRelation(array $attribute): bool
    {
        return ($attribute['type'] ?? null) === 'relation';
    }

    /**
     * Check if the given attribute is an enum
     *
     * @param array<string, mixed> $attribute
     */
    public function isEnumeration(array $attribute): bool
    {
        return ($attribute['type'] ?? null) === 'enumeration';
    }

    /**
     * Check if the given attribute is a component
     *
     * @param array<string, mixed> $attribute
     */
    public function isComponent(array $attribute): bool
    {
        return ($attribute['type'] ?? null) === 'component';
    }

    /**
     * Check if the given attribute is a dynamic zone
     *
     * @param array<string, mixed> $attribute
     */
    public function isDynamicZone(array $attribute): bool
    {
        return ($attribute['type'] ?? null) === 'dynamiczone';
    }
}
