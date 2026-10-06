<?php

declare(strict_types=1);

namespace Strapi\Database\EntityManager;

use Strapi\Database\Database;
use Strapi\Database\Query\QueryBuilder;

/** Port of packages/core/database/src/entity-manager/morph-relations.ts. */
final class MorphRelations
{
    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function getMorphToManyRowsLinkedToMorphOne(array $rows, string $uid, string $attributeName, string $typeColumnName, Database $db): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($uid, $attributeName, $typeColumnName, $db): bool {
            $relatedType = (string) ($row[$typeColumnName] ?? '');
            $field = (string) ($row['field'] ?? '');

            if (!$db->metadata->has($relatedType)) {
                return false;
            }

            $targetAttribute = $db->metadata->get($relatedType)['attributes'][$field] ?? null;

            return $targetAttribute !== null
                && ($targetAttribute['target'] ?? null) === $uid
                && ($targetAttribute['morphBy'] ?? null) === $attributeName
                && ($targetAttribute['relation'] ?? null) === 'morphOne';
        }));
    }

    /**
     * After writing morphToMany rows, remove previous links of the morphOne attributes they point
     * to (a morphOne target can only be linked once).
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $joinTable
     */
    public static function deleteRelatedMorphOneRelationsAfterMorphToManyUpdate(array $rows, string $uid, string $attributeName, array $joinTable, Database $db, mixed $trx = null): void
    {
        $idColumn = $joinTable['morphColumn']['idColumn'];
        $typeColumn = $joinTable['morphColumn']['typeColumn'];

        $morphOneRows = self::getMorphToManyRowsLinkedToMorphOne($rows, $uid, $attributeName, $typeColumn['name'], $db);

        $grouped = [];
        foreach ($morphOneRows as $row) {
            $grouped[(string) $row[$typeColumn['name']]][(string) $row['field']][] = $row[$idColumn['name']];
        }

        $orWhere = [];
        foreach ($grouped as $type => $fields) {
            foreach ($fields as $field => $ids) {
                $orWhere[] = [
                    $typeColumn['name'] => $type,
                    'field' => $field,
                    $idColumn['name'] => ['$in' => $ids],
                ];
            }
        }

        if ($orWhere !== []) {
            (new QueryBuilder($joinTable['name'], $db))
                ->delete()
                ->where(['$or' => $orWhere])
                ->transacting($trx)
                ->execute();
        }
    }

    public static function encodePolymorphicId(int|string $id, string $type): string
    {
        return "{$id}:::{$type}";
    }

    /**
     * Encodes the id (and positional ids) of a relation with its `__type` into a single unique key.
     *
     * @param array<string, mixed> $relation
     *
     * @return array<string, mixed>
     */
    public static function encodePolymorphicRelation(string $idColumn, string $typeColumn, array $relation): array
    {
        $newRelation = [...$relation, $idColumn => self::encodePolymorphicId($relation[$idColumn], (string) ($relation[$typeColumn] ?? $relation['__type'] ?? ''))];

        if (!empty($relation['position'])) {
            $type = (string) ($relation['position']['__type'] ?? $relation['__type'] ?? '');
            $newRelation['position'] = $relation['position'];

            if (!empty($relation['position']['before'])) {
                $newRelation['position']['before'] = self::encodePolymorphicId($relation['position']['before'], $type);
            }
            if (!empty($relation['position']['after'])) {
                $newRelation['position']['after'] = self::encodePolymorphicId($relation['position']['after'], $type);
            }
        }

        return $newRelation;
    }
}
