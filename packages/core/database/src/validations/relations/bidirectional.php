<?php

declare(strict_types=1);

namespace Strapi\Database\Validations\Relations;

use Strapi\Database\Database;
use Strapi\Database\Utils\Identifiers\Identifiers;
use Strapi\Database\Utils\LodashWords;

/**
 * Port of packages/core/database/src/validations/relations/bidirectional.ts.
 *
 * If both sides of a bidirectional relation use `inversedBy`, two join tables exist; warn about
 * the side that should switch to `mappedBy` without losing data.
 */
final class Bidirectional
{
    /** @return list<array{relation: array<string, mixed>, invRelation: array<string, mixed>}> */
    private static function getLinksWithoutMappedBy(Database $db): array
    {
        $relationsToUpdate = [];

        foreach ($db->metadata as $modelMetadata) {
            foreach ($modelMetadata['attributes'] as $attribute) {
                if (($attribute['type'] ?? null) !== 'relation') {
                    continue;
                }

                if (!empty($attribute['inversedBy']) && isset($attribute['target']) && $db->metadata->has($attribute['target'])) {
                    $invRelation = $db->metadata->get($attribute['target'])['attributes'][$attribute['inversedBy']] ?? null;

                    // Both relations use inversedBy.
                    if ($invRelation !== null && !empty($invRelation['inversedBy']) && isset($attribute['joinTable']['name'])) {
                        $relationsToUpdate[$attribute['joinTable']['name']] = ['relation' => $attribute, 'invRelation' => $invRelation];
                    }
                }
            }
        }

        return array_values($relationsToUpdate);
    }

    private static function isLinkTableEmpty(Database $db, string $linkTableName): bool
    {
        if (!$db->connection->createSchemaManager()->tableExists($linkTableName)) {
            return true;
        }

        $count = $db->connection->fetchOne('SELECT COUNT(*) FROM ' . $db->connection->quoteSingleIdentifier($linkTableName));

        return (int) $count === 0;
    }

    public static function validateBidirectionalRelations(Database $db): void
    {
        $identifiers = Identifiers::global();

        foreach (self::getLinksWithoutMappedBy($db) as ['relation' => $relation, 'invRelation' => $invRelation]) {
            $modelMetadata = $db->metadata->get($invRelation['target']);
            $invModelMetadata = $db->metadata->get($relation['target']);

            $joinTableName = $identifiers->getJoinTableName(LodashWords::snakeCase($modelMetadata['tableName']), LodashWords::snakeCase($invRelation['inversedBy']));
            $inverseJoinTableName = $identifiers->getJoinTableName(LodashWords::snakeCase($invModelMetadata['tableName']), LodashWords::snakeCase($relation['inversedBy']));

            $joinTableEmpty = self::isLinkTableEmpty($db, $joinTableName);
            $inverseJoinTableEmpty = self::isLinkTableEmpty($db, $inverseJoinTableName);

            if ($joinTableEmpty) {
                $db->logger->warning(sprintf(
                    'Error on attribute "%s" in model "%s" (%s). Please modify your %s schema by renaming the key "inversedBy" to "mappedBy". Ex: { "inversedBy": "%s" } -> { "mappedBy": "%s" }',
                    $invRelation['inversedBy'],
                    $modelMetadata['singularName'],
                    $modelMetadata['uid'],
                    $modelMetadata['singularName'],
                    $relation['inversedBy'],
                    $relation['inversedBy'],
                ));
            } elseif ($inverseJoinTableEmpty) {
                $db->logger->warning(sprintf(
                    'Error on attribute "%s" in model "%s" (%s). Please modify your %s schema by renaming the key "inversedBy" to "mappedBy". Ex: { "inversedBy": "%s" } -> { "mappedBy": "%s" }',
                    $relation['inversedBy'],
                    $invModelMetadata['singularName'],
                    $invModelMetadata['uid'],
                    $invModelMetadata['singularName'],
                    $invRelation['inversedBy'],
                    $invRelation['inversedBy'],
                ));
            }
        }
    }
}
