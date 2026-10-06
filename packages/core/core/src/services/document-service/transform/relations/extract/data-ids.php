<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Transform\Relations\Extract;

use Strapi\Core\Services\DocumentService\Transform\IdMap;
use Strapi\Core\Services\DocumentService\Transform\Relations\Utils\Dp;
use Strapi\Core\Services\DocumentService\Transform\Relations\Utils\I18n;
use Strapi\Core\Services\DocumentService\Transform\Relations\Utils\MapRelation;
use Strapi\Core\Services\DocumentService\Transform\Relations\Utils\XtoOne;
use Strapi\Core\Strapi;
use Strapi\Utils\Relations;
use Strapi\Utils\Traverse\VisitorOptions;

/**
 * Port of transform/relations/extract/data-ids.ts: iterate over all relations and media of a data
 * object and register their document ids in the id map.
 *
 * @phpstan-type Options array{uid: string, locale?: string|null, status?: 'draft'|'published'|null}
 */
final class DataIds
{
    private const MEDIA_UID = 'plugin::upload.file';

    /**
     * Load a relation documentId into the idMap.
     *
     * @param Options $source
     * @param array<string, mixed> $relation
     */
    private static function addRelationDocId(Strapi $strapi, IdMap $idMap, array $source, string $targetUid, array $relation): void
    {
        $targetLocale = I18n::getRelationTargetLocale($strapi, $relation, ['targetUid' => $targetUid, 'sourceUid' => $source['uid'], 'sourceLocale' => $source['locale'] ?? null]);

        $targetStatus = Dp::getRelationTargetStatus($strapi, $relation, ['targetUid' => $targetUid, 'sourceUid' => $source['uid'], 'sourceStatus' => $source['status'] ?? null]);

        foreach ($targetStatus as $status) {
            $idMap->add(['uid' => $targetUid, 'documentId' => $relation['documentId'], 'locale' => $targetLocale, 'status' => $status]);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param Options $source
     */
    public static function extractDataIds(Strapi $strapi, IdMap $idMap, array $data, array $source): mixed
    {
        return MapRelation::traverseEntityRelations(
            static function (VisitorOptions $options) use ($strapi, $idMap, $source): void {
                $attribute = $options->attribute;
                if ($attribute === null) {
                    return;
                }
                $isPolymorphicRelation = Relations::isPolymorphic($attribute);

                // Skip looking up entries we're about to discard.
                $normalizedValue = XtoOne::normalizeXToOneRelationValue($attribute, $options->value);

                MapRelation::mapRelation(static function (mixed $relation) use ($strapi, $idMap, $source, $attribute, $isPolymorphicRelation): mixed {
                    if (!is_array($relation) || empty($relation['documentId'])) {
                        return $relation;
                    }

                    // Regular relations will always target the same target; polymorphic ones carry it in the data
                    if (($attribute['type'] ?? null) === 'media') {
                        $targetUid = self::MEDIA_UID;
                    } elseif ($isPolymorphicRelation) {
                        $targetUid = (string) ($relation['__type'] ?? '');
                    } else {
                        $targetUid = (string) $attribute['target'];
                    }

                    self::addRelationDocId($strapi, $idMap, $source, $targetUid, $relation);

                    // Handle positional arguments
                    $position = is_array($relation['position'] ?? null) ? $relation['position'] : null;

                    // The positional relation target uid can be different for polymorphic relations
                    $positionTargetUid = $targetUid;
                    if ($isPolymorphicRelation && !empty($position['__type'])) {
                        $positionTargetUid = (string) $position['__type'];
                    }

                    if (!empty($position['before'])) {
                        self::addRelationDocId($strapi, $idMap, $source, $positionTargetUid, [...$relation, ...$position, 'documentId' => $position['before']]);
                    }

                    if (!empty($position['after'])) {
                        self::addRelationDocId($strapi, $idMap, $source, $positionTargetUid, [...$relation, ...$position, 'documentId' => $position['after']]);
                    }

                    return $relation;
                }, $normalizedValue);
            },
            ['schema' => $strapi->getModel($source['uid']), 'getModel' => static fn (string $uid) => $strapi->getModel($uid), 'includeMedia' => true],
            $data,
        );
    }
}
