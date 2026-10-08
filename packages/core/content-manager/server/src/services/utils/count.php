<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services\Utils;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;

/** Port of server/src/services/utils/count.ts. */
final class Count
{
    private static function getCountForRelation(string $attributeName, mixed $entity, Schema $model): mixed
    {
        // do not count createdBy, updatedBy, localizations etc.
        if (!ContentTypes::isVisibleAttribute($model, $attributeName)) {
            return $entity;
        }

        if (is_array($entity) && array_is_list($entity)) {
            return ['count' => count($entity)];
        }

        return self::truthy($entity) ? ['count' => 1] : ['count' => 0];
    }

    /** @return list<array<string, mixed>> */
    private static function getCountForDZ(Strapi $strapi, mixed $entity): array
    {
        return array_map(
            static fn (array $component): array => self::getDeepRelationsCount($strapi, $component, (string) ($component['__component'] ?? '')),
            is_array($entity) ? array_values($entity) : [],
        );
    }

    private static function getCountFor(Strapi $strapi, string $attributeName, mixed $entity, Schema $model): mixed
    {
        $attribute = $model->attributes[$attributeName] ?? null;

        switch ($attribute['type'] ?? null) {
            case 'relation':
                return self::getCountForRelation($attributeName, $entity, $model);
            case 'component':
                if (!self::truthy($entity) || !is_array($entity)) {
                    return null;
                }
                $componentUid = (string) ($attribute['component'] ?? '');
                if (($attribute['repeatable'] ?? false) === true) {
                    return array_map(
                        static fn (array $component): array => self::getDeepRelationsCount($strapi, $component, $componentUid),
                        array_values($entity),
                    );
                }

                return self::getDeepRelationsCount($strapi, $entity, $componentUid);
            case 'dynamiczone':
                return self::getCountForDZ($strapi, $entity);
            default:
                return $entity;
        }
    }

    /**
     * @param array<string, mixed> $entity
     * @return array<string, mixed>
     */
    public static function getDeepRelationsCount(Strapi $strapi, array $entity, string $uid): array
    {
        $model = $strapi->getModel($uid);
        if ($model === null) {
            throw new \RuntimeException("Model {$uid} not found");
        }

        $relationCountEntity = [];
        foreach ($entity as $attributeName => $value) {
            $relationCountEntity[$attributeName] = self::getCountFor($strapi, (string) $attributeName, $value, $model);
        }

        return $relationCountEntity;
    }

    private static function truthy(mixed $value): bool
    {
        return $value !== null && $value !== false && $value !== 0 && $value !== '' && $value !== 0.0;
    }
}
