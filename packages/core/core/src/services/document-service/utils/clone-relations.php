<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Utils;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;

/**
 * Port of utils/clone-relations.ts: when cloning a document, split its data into the fields to
 * write and the join-table relations to copy row by row (keeping pivot/order columns).
 */
final class CloneRelations
{
    /**
     * @param array<string, mixed> $originalData the populated source entry (without id/createdAt/updatedAt)
     * @param array<string, mixed>|null $overrides `params.data`
     * @param callable(string): (Schema|null) $getModel
     * @return array{data: array<string, mixed>, relationsToCopy: list<string>}
     */
    public static function prepareCloneData(Strapi $strapi, array $originalData, ?array $overrides, Schema $contentType, callable $getModel): array
    {
        $data = $originalData;
        $relationsToCopy = [];

        foreach ($contentType->attributes as $name => $attribute) {
            $type = $attribute['type'] ?? null;
            if ($type === 'relation') {
                if (!empty($attribute['unstable_virtual'])) {
                    unset($data[$name]);
                    continue;
                }
                if (($attribute['useJoinTable'] ?? null) === false || in_array($attribute['relation'] ?? null, ['morphToOne'], true)) {
                    // join-column relations are copied as plain values
                    continue;
                }
                // join-table relations are copied row by row after the entry exists (keeps order/pivot columns)
                if (array_key_exists($name, $data) && !array_key_exists($name, $overrides ?? [])) {
                    unset($data[$name]);
                    $relationsToCopy[] = (string) $name;
                }
                continue;
            }
            if ($type === 'media') {
                // media is a morph relation stored on the files side: keep the ids
                if (isset($data[$name])) {
                    $data[$name] = self::toIds($data[$name]);
                }
                continue;
            }
            if ($type === 'component' || $type === 'dynamiczone') {
                // components are re-created from their data (ids stripped)
                if (isset($data[$name])) {
                    $data[$name] = self::stripComponentIds($data[$name]);
                }
            }
        }

        return ['data' => [...$data, ...($overrides ?? [])], 'relationsToCopy' => $relationsToCopy];
    }

    private static function toIds(mixed $value): mixed
    {
        if (is_array($value) && array_is_list($value)) {
            return array_map(static fn (mixed $v): mixed => is_array($v) ? ($v['id'] ?? null) : $v, $value);
        }

        return is_array($value) ? ($value['id'] ?? null) : $value;
    }

    private static function stripComponentIds(mixed $value): mixed
    {
        if (is_array($value) && array_is_list($value)) {
            return array_map(self::stripComponentIds(...), $value);
        }
        if (is_array($value)) {
            unset($value['id'], $value['documentId']);
            foreach ($value as $k => $v) {
                if (is_array($v)) {
                    $value[$k] = self::stripComponentIds($v);
                }
            }
        }

        return $value;
    }

    /**
     * Copy the join-table rows of $relations from the source entry to the clone.
     *
     * @param list<string> $relations
     */
    public static function copyCloneRelationRows(Strapi $strapi, string $uid, int|string $sourceEntryId, int|string $targetEntryId, array $relations): void
    {
        if ($relations === []) {
            return;
        }
        $meta = $strapi->db()->metadata->get($uid);
        $idColumn = \Strapi\Database\Utils\Identifiers\Identifiers::ID_COLUMN;

        foreach ($relations as $name) {
            $attribute = $meta['attributes'][$name] ?? null;
            $joinTable = $attribute['joinTable'] ?? null;
            if (!is_array($joinTable)) {
                continue;
            }
            $sourceColumn = $joinTable['joinColumn']['name'];
            $rows = RelationSyncHelpers::selectWhereIn($strapi, $joinTable['name'], $sourceColumn, [$sourceEntryId]);
            $copies = array_map(static fn (array $row): array => [...RelationSyncHelpers::omitId($row, $idColumn), $sourceColumn => $targetEntryId], $rows);
            RelationSyncHelpers::batchInsert($strapi, $joinTable['name'], $copies);
        }
    }
}
