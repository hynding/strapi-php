<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Utils;

use Strapi\Core\Strapi;

/**
 * Port of utils/bidirectional-relations.ts: preserves the relation order of both sides of
 * bidirectional relations when an entry changes state (publish / discard re-creates it).
 *
 * @phpstan-type RelationEntry array{joinTable: array<string, mixed>, relations: list<array<string, mixed>>, entityColumn: string, relatedColumn: string}
 */
final class BidirectionalRelations
{
    /**
     * Draft id → published id for rows that already have a published counterpart (same document_id + locale).
     *
     * @param list<mixed> $rowIds
     * @return array<string, mixed>
     */
    private static function draftToPublishedMap(Strapi $strapi, string $tableName, array $rowIds): array
    {
        $uniqueIds = array_values(array_unique(array_map('strval', $rowIds)));
        if ($uniqueIds === []) {
            return [];
        }

        $draftEntries = $strapi->db()->sql()->from($tableName)->select(['id', 'document_id', 'locale'])->whereIn('id', $uniqueIds)->run();
        if (!is_array($draftEntries) || $draftEntries === []) {
            return [];
        }

        $pubEntries = $strapi->db()->sql()->from($tableName)->select(['id', 'document_id', 'locale'])
            ->whereNotNull('published_at')
            ->whereIn('document_id', array_values(array_unique(array_map(static fn (array $e): mixed => $e['document_id'], $draftEntries))))
            ->run();

        $pubByDocLocale = [];
        foreach (is_array($pubEntries) ? $pubEntries : [] as $e) {
            $pubByDocLocale["{$e['document_id']}_{$e['locale']}"] = $e['id'];
        }

        $map = [];
        foreach ($draftEntries as $d) {
            $pubId = $pubByDocLocale["{$d['document_id']}_{$d['locale']}"] ?? null;
            if ($pubId !== null) {
                $map[(string) $d['id']] = $pubId;
            }
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $idMap
     * @return list<array<string, mixed>>
     */
    private static function remapRelatedIds(array $rows, string $relatedCol, array $idMap): array
    {
        return array_map(static function (array $row) use ($relatedCol, $idMap): array {
            $next = $idMap[(string) $row[$relatedCol]] ?? null;

            return $next !== null ? [...$row, $relatedCol => $next] : $row;
        }, $rows);
    }

    /**
     * Reads join rows tied to the entry being published and returns batches for `sync()`.
     *
     * @param array{joinTable: array<string, mixed>, publishedCol: string, relatedCol: string, relatedUid: string, relatedHasDraftAndPublish: bool, schemaUid: string, isOwningSide: bool, oldVersions: list<array<string, mixed>>, newVersions: list<array<string, mixed>>} $opts
     * @return list<RelationEntry>
     */
    private static function captureJoinBatches(Strapi $strapi, array $opts): array
    {
        $joinTable = $opts['joinTable'];
        $publishedCol = $opts['publishedCol'];
        $relatedCol = $opts['relatedCol'];
        $batches = [];
        $table = $joinTable['name'];

        // Skip the old-published capture for the owning side: those rows are recreated in draft order by entries.publish
        $oldIds = $opts['isOwningSide'] ? [] : array_map(static fn (array $e): mixed => $e['id'], $opts['oldVersions']);
        if ($oldIds !== []) {
            $existing = RelationSyncHelpers::selectWhereIn($strapi, $table, $publishedCol, $oldIds);
            if ($existing !== []) {
                $batches[] = ['joinTable' => $joinTable, 'relations' => $existing, 'entityColumn' => $publishedCol, 'relatedColumn' => $relatedCol];
            }
        }

        if (!isset($strapi->contentTypes()[$opts['schemaUid']])) {
            return $batches;
        }

        $oldLocales = array_map(static fn (array $e): string => (string) ($e['locale'] ?? ''), $opts['oldVersions']);
        $draftsOnly = array_values(array_filter($opts['newVersions'], static fn (array $v): bool => !in_array((string) ($v['locale'] ?? ''), $oldLocales, true)));
        if ($draftsOnly === []) {
            return $batches;
        }

        $draftIds = array_map(static fn (array $e): mixed => $e['id'], $draftsOnly);
        $draftRows = RelationSyncHelpers::selectWhereIn($strapi, $table, $publishedCol, $draftIds);

        if ($draftRows === []) {
            return $batches;
        }

        $relations = $draftRows;
        if ($opts['relatedHasDraftAndPublish']) {
            $meta = $strapi->db()->metadata->get($opts['relatedUid']);
            $relatedIds = array_map(static fn (array $r): mixed => $r[$relatedCol], $draftRows);
            $map = self::draftToPublishedMap($strapi, $meta['tableName'], $relatedIds);
            $relations = self::remapRelatedIds($draftRows, $relatedCol, $map);
        }

        $batches[] = ['joinTable' => $joinTable, 'relations' => $relations, 'entityColumn' => $publishedCol, 'relatedColumn' => $relatedCol];

        return $batches;
    }

    /**
     * @param array{oldVersions: list<array<string, mixed>>, newVersions: list<array<string, mixed>>} $context
     * @return list<RelationEntry>
     */
    public static function load(Strapi $strapi, string $uid, array $context): array
    {
        $relationsToUpdate = [];

        $strapi->db()->transaction(static function () use ($strapi, $uid, $context, &$relationsToUpdate): void {
            $models = [...array_values($strapi->contentTypes()), ...array_values($strapi->components())];
            $contentTypes = $strapi->contentTypes();

            foreach ($models as $model) {
                $dbModel = $strapi->db()->metadata->get($model->uid);

                foreach ($dbModel['attributes'] as $attribute) {
                    $joinTable = $attribute['joinTable'] ?? null;

                    if (($attribute['type'] ?? null) !== 'relation' || !is_array($joinTable)) {
                        continue;
                    }

                    if (empty($attribute['inversedBy']) && empty($attribute['mappedBy'])) {
                        continue;
                    }

                    // Owning side: e.g. Author.articles when publishing an Author.
                    $isOwningSide = !empty($attribute['inversedBy']) && $model->uid === $uid && ($attribute['relation'] ?? null) === 'manyToMany' && $model->uid !== ($attribute['target'] ?? null);

                    // Inverse side: e.g. Article.authors when publishing an Article.
                    $isInverseSide = ($attribute['target'] ?? null) === $uid && $model->uid !== $uid;

                    if (!$isOwningSide && !$isInverseSide) {
                        continue;
                    }

                    // Direction determines which join column belongs to the entity being published
                    $publishedCol = $isOwningSide ? $joinTable['joinColumn']['name'] : $joinTable['inverseJoinColumn']['name'];
                    $relatedCol = $isOwningSide ? $joinTable['inverseJoinColumn']['name'] : $joinTable['joinColumn']['name'];

                    $relatedUid = (string) ($isOwningSide ? $attribute['target'] : $model->uid);

                    $batches = self::captureJoinBatches($strapi, [
                        'joinTable' => $joinTable,
                        'publishedCol' => $publishedCol,
                        'relatedCol' => $relatedCol,
                        'relatedUid' => $relatedUid,
                        'relatedHasDraftAndPublish' => $isOwningSide
                            ? (bool) ($contentTypes[$relatedUid]->options['draftAndPublish'] ?? false)
                            : (bool) ($model->options['draftAndPublish'] ?? false),
                        'schemaUid' => $model->uid,
                        'isOwningSide' => $isOwningSide,
                        'oldVersions' => $context['oldVersions'],
                        'newVersions' => $context['newVersions'],
                    ]);
                    foreach ($batches as $batch) {
                        $relationsToUpdate[] = $batch;
                    }
                }
            }
        });

        return $relationsToUpdate;
    }

    /**
     * Restore the order of the inverse relations (and re-insert cascade-deleted rows).
     *
     * @param list<array<string, mixed>> $oldEntries
     * @param list<array<string, mixed>> $newEntries
     * @param list<RelationEntry> $existingRelations
     */
    public static function sync(Strapi $strapi, array $oldEntries, array $newEntries, array $existingRelations): void
    {
        $newEntriesByLocale = [];
        foreach ($newEntries as $entry) {
            $newEntriesByLocale[(string) ($entry['locale'] ?? '')] = $entry;
        }
        $entryIdMapping = [];
        foreach ($oldEntries as $oldEntry) {
            $newEntry = $newEntriesByLocale[(string) ($oldEntry['locale'] ?? '')] ?? null;
            if ($newEntry !== null) {
                $entryIdMapping[(string) $oldEntry['id']] = $newEntry['id'];
            }
        }

        $republishedEntryIds = array_map(static fn (array $e): string => (string) $e['id'], $newEntries);
        $idColumn = \Strapi\Database\Utils\Identifiers\Identifiers::ID_COLUMN;

        $strapi->db()->transaction(static function () use ($strapi, $existingRelations, $entryIdMapping, $republishedEntryIds, $idColumn): void {
            foreach ($existingRelations as ['joinTable' => $joinTable, 'relations' => $relations, 'entityColumn' => $sourceColumn, 'relatedColumn' => $targetColumn]) {
                $orderColumn = $joinTable['orderColumnName'] ?? null;

                // Failsafe in case those don't exist
                if (!$sourceColumn || !$targetColumn || !$orderColumn) {
                    continue;
                }

                $mapped = [];
                foreach ($relations as $relation) {
                    $newSourceId = $entryIdMapping[(string) $relation[$sourceColumn]] ?? null;
                    if ($newSourceId === null) {
                        continue;
                    }
                    $mapped[] = ['relation' => $relation, 'targetId' => $relation[$targetColumn], 'originalOrder' => $relation[$orderColumn] ?? null, 'newSourceId' => $newSourceId];
                }

                if ($mapped === []) {
                    continue;
                }

                $newSourceIds = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['newSourceId'], $mapped)));

                // Update each row's order (one UPDATE per pair: portable across the three dialects)
                foreach ($mapped as ['newSourceId' => $newSourceId, 'targetId' => $targetId, 'originalOrder' => $originalOrder]) {
                    $strapi->db()->sql()->from($joinTable['name'])
                        ->where([$sourceColumn => $newSourceId, $targetColumn => $targetId])
                        ->update([$orderColumn => $originalOrder])
                        ->run();
                }

                // Find which rows exist so we know what to insert
                $existingRows = $strapi->db()->sql()->from($joinTable['name'])->select([$sourceColumn, $targetColumn])->whereIn($sourceColumn, $newSourceIds)->run();
                $existingSet = [];
                foreach (is_array($existingRows) ? $existingRows : [] as $r) {
                    $existingSet["{$r[$sourceColumn]}:{$r[$targetColumn]}"] = true;
                }

                // Insert cascade-deleted rows that aren't from republished sources
                $toInsert = [];
                foreach ($mapped as ['relation' => $relation, 'newSourceId' => $newSourceId, 'targetId' => $targetId, 'originalOrder' => $originalOrder]) {
                    if (isset($existingSet["{$newSourceId}:{$targetId}"]) || in_array((string) $newSourceId, $republishedEntryIds, true)) {
                        continue;
                    }
                    $toInsert[] = [...RelationSyncHelpers::omitId($relation, $idColumn), $sourceColumn => $newSourceId, $orderColumn => $originalOrder];
                }

                RelationSyncHelpers::batchInsert($strapi, $joinTable['name'], $toInsert);
            }
        });
    }
}
