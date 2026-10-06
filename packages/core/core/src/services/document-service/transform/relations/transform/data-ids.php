<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Transform\Relations\Transform;

use Strapi\Core\Services\DocumentService\Transform\IdMap;
use Strapi\Core\Services\DocumentService\Transform\Relations\Utils\Dp;
use Strapi\Core\Services\DocumentService\Transform\Relations\Utils\I18n;
use Strapi\Core\Services\DocumentService\Transform\Relations\Utils\MapRelation;
use Strapi\Core\Services\DocumentService\Transform\Relations\Utils\XtoOne;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Relations;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;

/**
 * Port of transform/relations/transform/data-ids.ts: iterate over all relations and media of a data
 * object and transform document ids to entity ids.
 *
 * @phpstan-type Options array{uid: string, locale?: string|null, status?: 'draft'|'published'|null, allowMissingId?: bool}
 */
final class DataIds
{
    private const MEDIA_UID = 'plugin::upload.file';

    /**
     * Get the entry ids for a given documentId.
     *
     * @param Options $source
     * @param array<string, mixed> $relation
     * @return list<string|int>
     */
    private static function getRelationIds(Strapi $strapi, IdMap $idMap, array $source, string $targetUid, array $relation): array
    {
        // locale to connect to
        $targetLocale = I18n::getRelationTargetLocale($strapi, $relation, ['targetUid' => $targetUid, 'sourceUid' => $source['uid'], 'sourceLocale' => $source['locale'] ?? null]);

        // status(es) to connect to
        $targetStatus = Dp::getRelationTargetStatus($strapi, $relation, ['targetUid' => $targetUid, 'sourceUid' => $source['uid'], 'sourceStatus' => $source['status'] ?? null]);

        $ids = [];

        // Find mapping between documentID -> id(s). A single documentID can map to multiple ids
        // (e.g. connecting Non DP -> DP connects both the draft and published version at the same time)
        foreach ($targetStatus as $tStatus) {
            $entryId = $idMap->get(['uid' => $targetUid, 'documentId' => $relation['documentId'], 'locale' => $targetLocale, 'status' => $tStatus]);

            if ($entryId !== null) {
                $ids[] = $entryId;
            }
        }

        if ($ids === [] && !($source['allowMissingId'] ?? false)) {
            throw new ValidationError("Document with id \"{$relation['documentId']}\", locale \"{$targetLocale}\" not found");
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $data
     * @param Options $source
     */
    public static function transformDataIdsVisitor(Strapi $strapi, IdMap $idMap, array $data, array $source): mixed
    {
        return MapRelation::traverseEntityRelations(
            static function (VisitorOptions $options, VisitorUtils $utils) use ($strapi, $idMap, $source): void {
                $attribute = $options->attribute;
                if ($attribute === null) {
                    return;
                }
                $isPolymorphicRelation = Relations::isPolymorphic($attribute);

                // Collapse a "relates to one" payload to a single entry first.
                $normalizedValue = XtoOne::normalizeXToOneRelationValue($attribute, $options->value);

                // Transform the relation documentId to entity id
                $newRelation = MapRelation::mapRelation(static function (mixed $relation) use ($strapi, $idMap, $source, $attribute, $isPolymorphicRelation): mixed {
                    if (!is_array($relation) || empty($relation['documentId'])) {
                        return $relation;
                    }

                    if (($attribute['type'] ?? null) === 'media') {
                        $targetUid = self::MEDIA_UID;
                    } elseif ($isPolymorphicRelation) {
                        $targetUid = (string) ($relation['__type'] ?? '');
                    } else {
                        $targetUid = (string) $attribute['target'];
                    }
                    $ids = self::getRelationIds($strapi, $idMap, $source, $targetUid, $relation);

                    // Handle positional arguments
                    $position = is_array($relation['position'] ?? null) ? $relation['position'] : [];

                    // The positional relation target uid can be different for polymorphic relations
                    $positionTargetUid = $targetUid;
                    if ($isPolymorphicRelation && !empty($position['__type'])) {
                        $positionTargetUid = (string) $position['__type'];
                    }

                    if (!empty($position['before'])) {
                        $beforeRelation = [...$relation, ...$position, 'documentId' => $position['before']];
                        $beforeIds = self::getRelationIds($strapi, $idMap, $source, $positionTargetUid, $beforeRelation);
                        $position['before'] = $beforeIds[0] ?? null;
                    }

                    if (!empty($position['after'])) {
                        $afterRelation = [...$relation, ...$position, 'documentId' => $position['after']];
                        $afterIds = self::getRelationIds($strapi, $idMap, $source, $positionTargetUid, $afterRelation);
                        $position['after'] = $afterIds[0] ?? null;
                    }

                    // Transform all ids to new relations
                    $out = [];
                    foreach ($ids as $id) {
                        $new = ['id' => $id];

                        if (isset($relation['position'])) {
                            $new['position'] = $position;
                        }

                        // Insert type if its a polymorphic relation
                        if ($isPolymorphicRelation) {
                            $new['__type'] = $targetUid;
                        }

                        $out[] = $new;
                    }

                    return $out;
                }, $normalizedValue);

                $utils->set($options->key, $newRelation);
            },
            ['schema' => $strapi->getModel($source['uid']), 'getModel' => static fn (string $uid) => $strapi->getModel($uid), 'includeMedia' => true],
            $data,
        );
    }
}
