<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

use Strapi\Database\Utils\Identifiers\Identifiers;
use Strapi\Database\Utils\SchemaFactory;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\Cuid2;

/**
 * Port of packages/core/core/src/utils/transform-content-types-to-models.ts. The logic lives in
 * {@see SchemaFactory} (database package) so both packages share one implementation; this file
 * keeps upstream's names.
 *
 * @phpstan-import-type Model from \Strapi\Database\Metadata\Metadata
 */
final class TransformContentTypesToModels
{
    public static function createDocumentId(): string
    {
        return Cuid2::createId();
    }

    /**
     * @param iterable<Schema> $contentTypes
     * @return list<Model>
     */
    public static function transformContentTypesToModels(iterable $contentTypes, ?Identifiers $identifiers = null): array
    {
        return SchemaFactory::toModels($contentTypes, $identifiers);
    }

    public static function getComponentJoinTableName(string $collectionName, Identifiers $identifiers): string
    {
        return SchemaFactory::getComponentJoinTableName($collectionName, $identifiers);
    }

    public static function getDzJoinTableName(string $collectionName, Identifiers $identifiers): string
    {
        return SchemaFactory::getDzJoinTableName($collectionName, $identifiers);
    }

    public static function getComponentJoinColumnEntityName(Identifiers $identifiers): string
    {
        return SchemaFactory::getComponentJoinColumnEntityName($identifiers);
    }

    public static function getComponentJoinColumnInverseName(Identifiers $identifiers): string
    {
        return SchemaFactory::getComponentJoinColumnInverseName($identifiers);
    }

    public static function getComponentTypeColumn(Identifiers $identifiers): string
    {
        return SchemaFactory::getComponentTypeColumn($identifiers);
    }

    public static function getComponentFkIndexName(string $contentType, Identifiers $identifiers): string
    {
        return SchemaFactory::getComponentFkIndexName($contentType, $identifiers);
    }
}
