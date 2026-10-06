<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Utils;

use Strapi\Core\Strapi;

/**
 * Port of utils/self-referential-relations.ts: preserves self-referential relations (both sides of
 * the same content type) across the delete-and-recreate cycle of publish/discard.
 *
 * @phpstan-type RelationData array{joinTable: array<string, mixed>, relations: list<array<string, mixed>>, remap?: array{source: bool, target: bool}}
 */
final class SelfReferentialRelations
{
    /**
     * @param list<mixed> $idsToResolve
     * @return array<string, mixed>
     */
    private static function getCounterparts(Strapi $strapi, string $tableName, array $idsToResolve, string $status): array
    {
        $ids = array_values(array_unique(array_map('strval', $idsToResolve)));
        if ($ids === []) {
            return [];
        }

        $drafts = $strapi->db()->sql()->from($tableName)->select(['id', 'document_id', 'locale'])->whereIn('id', $ids)->run();
        if (!is_array($drafts) || $drafts === []) {
            return [];
        }

        $documentIds = array_values(array_unique(array_map(static fn (array $e): mixed => $e['document_id'], $drafts)));

        $qb = $strapi->db()->sql()->from($tableName)->select(['id', 'document_id', 'locale'])->whereIn('document_id', $documentIds);
        $counterparts = $status === 'published' ? $qb->whereNotNull('published_at')->run() : $qb->whereNull('published_at')->run();

        $byKey = [];
        foreach (is_array($counterparts) ? $counterparts : [] as $entry) {
            $byKey["{$entry['document_id']}:{$entry['locale']}"] = $entry;
        }

        $acc = [];
        foreach ($drafts as $draft) {
            $counterpart = $byKey["{$draft['document_id']}:{$draft['locale']}"] ?? null;
            if ($counterpart !== null) {
                $acc[(string) $draft['id']] = $counterpart['id'];
            }
        }

        return $acc;
    }

    /**
     * Loads self-referential relations from source entries before they are deleted/recreated.
     *
     * @param list<array<string, mixed>> $sourceEntries
     * @return list<RelationData>
     */
    public static function load(Strapi $strapi, string $uid, array $sourceEntries, string $targetStatus): array
    {
        $updates = [];
        $dbModel = $strapi->db()->metadata->get($uid);

        $strapi->db()->transaction(static function () use ($strapi, $uid, $sourceEntries, $targetStatus, $dbModel, &$updates): void {
            foreach ($dbModel['attributes'] as $attribute) {
                if (($attribute['type'] ?? null) !== 'relation' || ($attribute['target'] ?? null) !== $uid) {
                    continue;
                }

                // Bidirectional inverse side shares the same physical join table as the owning attribute
                if (!empty($attribute['mappedBy'])) {
                    continue;
                }

                $joinTable = $attribute['joinTable'] ?? null;
                if (!is_array($joinTable)) {
                    continue;
                }

                $sourceColumnName = $joinTable['joinColumn']['name'];
                $targetColumnName = $joinTable['inverseJoinColumn']['name'];

                $sourceIds = array_map(static fn (array $entry): string => (string) $entry['id'], $sourceEntries);
                if ($sourceIds === []) {
                    continue;
                }

                // Relations where both source and target are among the entries being processed
                $selfRelations = $strapi->db()->sql()->from($joinTable['name'])->select('*')
                    ->whereIn($sourceColumnName, $sourceIds)->whereIn($targetColumnName, $sourceIds)->run();
                $selfRelations = is_array($selfRelations) ? $selfRelations : [];

                if ($selfRelations !== []) {
                    $updates[] = ['joinTable' => $joinTable, 'relations' => $selfRelations, 'remap' => ['source' => true, 'target' => true]];
                }

                // One-sided self-relations: use rows from the state being copied as the source of truth
                $sourceDraftRelations = RelationSyncHelpers::selectWhereIn($strapi, $joinTable['name'], $sourceColumnName, $sourceIds, $targetColumnName, $sourceIds);
                $targetDraftRelations = RelationSyncHelpers::selectWhereIn($strapi, $joinTable['name'], $targetColumnName, $sourceIds, $sourceColumnName, $sourceIds);

                $targetCounterparts = self::getCounterparts($strapi, $dbModel['tableName'], array_map(static fn (array $r): mixed => $r[$targetColumnName], $sourceDraftRelations), $targetStatus);
                $sourceCounterparts = self::getCounterparts($strapi, $dbModel['tableName'], array_map(static fn (array $r): mixed => $r[$sourceColumnName], $targetDraftRelations), $targetStatus);

                if ($sourceDraftRelations !== []) {
                    $relations = [];
                    foreach ($sourceDraftRelations as $relation) {
                        $counterpart = $targetCounterparts[(string) $relation[$targetColumnName]] ?? null;
                        if ($counterpart !== null) {
                            $relations[] = [...$relation, $targetColumnName => $counterpart];
                        }
                    }
                    if ($relations !== []) {
                        $updates[] = ['joinTable' => $joinTable, 'relations' => $relations, 'remap' => ['source' => true, 'target' => false]];
                    }
                }

                if ($targetDraftRelations !== []) {
                    $relations = [];
                    foreach ($targetDraftRelations as $relation) {
                        $counterpart = $sourceCounterparts[(string) $relation[$sourceColumnName]] ?? null;
                        if ($counterpart !== null) {
                            $relations[] = [...$relation, $sourceColumnName => $counterpart];
                        }
                    }
                    if ($relations !== []) {
                        $updates[] = ['joinTable' => $joinTable, 'relations' => $relations, 'remap' => ['source' => false, 'target' => true]];
                    }
                }
            }
        });

        return $updates;
    }

    /**
     * Remap old entry ids to new entry ids and insert the remapped relations.
     *
     * @param list<array<string, mixed>> $sourceEntries
     * @param list<array<string, mixed>> $targetEntries
     * @param list<RelationData> $relationData
     */
    public static function sync(Strapi $strapi, array $sourceEntries, array $targetEntries, array $relationData): void
    {
        if ($relationData === []) {
            return;
        }

        $targetByLocale = [];
        foreach ($targetEntries as $entry) {
            $targetByLocale[(string) ($entry['locale'] ?? '')] = $entry;
        }
        $idMapping = [];
        foreach ($sourceEntries as $sourceEntry) {
            $targetEntry = $targetByLocale[(string) ($sourceEntry['locale'] ?? '')] ?? null;
            if ($targetEntry !== null) {
                $idMapping[(string) $sourceEntry['id']] = $targetEntry['id'];
            }
        }

        $idColumn = \Strapi\Database\Utils\Identifiers\Identifiers::ID_COLUMN;

        $strapi->db()->transaction(static function () use ($strapi, $relationData, $idMapping, $idColumn): void {
            foreach ($relationData as $data) {
                $joinTable = $data['joinTable'];
                $relations = $data['relations'];
                $remap = $data['remap'] ?? ['source' => true, 'target' => true];
                $sourceColumn = $joinTable['joinColumn']['name'];
                $targetColumn = $joinTable['inverseJoinColumn']['name'];

                $newRelations = [];
                foreach ($relations as $relation) {
                    $newSourceId = $remap['source'] ? ($idMapping[(string) $relation[$sourceColumn]] ?? null) : $relation[$sourceColumn];
                    $newTargetId = $remap['target'] ? ($idMapping[(string) $relation[$targetColumn]] ?? null) : $relation[$targetColumn];

                    // Any side being remapped must resolve to a new entry.
                    if ($newSourceId === null || $newTargetId === null) {
                        continue;
                    }

                    $newRelations[] = [...RelationSyncHelpers::omitId($relation, $idColumn), $sourceColumn => $newSourceId, $targetColumn => $newTargetId];
                }

                $pairKey = static fn (array $r): string => "{$r[$sourceColumn]}:{$r[$targetColumn]}";
                $seen = [];
                $deduped = [];
                foreach ($newRelations as $r) {
                    $key = $pairKey($r);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $deduped[] = $r;
                }

                if ($deduped === []) {
                    continue;
                }

                // Relation writes may have already recreated the remapped pair: restore the saved metadata instead
                $newSourceIds = array_values(array_unique(array_map(static fn (array $r): string => (string) $r[$sourceColumn], $deduped)));
                $existingRows = $strapi->db()->sql()->from($joinTable['name'])->select([$sourceColumn, $targetColumn])->whereIn($sourceColumn, $newSourceIds)->run();
                $existingSet = [];
                foreach (is_array($existingRows) ? $existingRows : [] as $r) {
                    $existingSet[$pairKey($r)] = true;
                }

                $toInsert = [];
                foreach ($deduped as $relation) {
                    if (!isset($existingSet[$pairKey($relation)])) {
                        $toInsert[] = $relation;
                        continue;
                    }
                    $dataToRestore = [];
                    foreach ([$joinTable['orderColumnName'] ?? null, $joinTable['inverseOrderColumnName'] ?? null] as $columnName) {
                        if ($columnName !== null && array_key_exists($columnName, $relation)) {
                            $dataToRestore[$columnName] = $relation[$columnName];
                        }
                    }
                    if ($dataToRestore !== []) {
                        $strapi->db()->sql()->from($joinTable['name'])
                            ->where([$sourceColumn => $relation[$sourceColumn], $targetColumn => $relation[$targetColumn]])
                            ->update($dataToRestore)->run();
                    }
                }

                RelationSyncHelpers::batchInsert($strapi, $joinTable['name'], $toInsert);
            }
        });
    }
}
