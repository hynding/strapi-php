<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Utils;

use Strapi\Core\Services\DocumentService\Components;
use Strapi\Core\Strapi;
use Strapi\Database\Database;
use Strapi\Types\Schema\Schema;

/**
 * Port of utils/clean-component-join-table.ts: removes ghost relations with publication state
 * mismatches from a join table (a D&P source linked to both the draft and the published version of
 * the same target). Used once at bootstrap through `db.repair.processUnidirectionalJoinTables`.
 */
final class CleanComponentJoinTable
{
    /** @return \Closure(Database, string, array<string, mixed>, array<string, mixed>): int */
    public static function create(Strapi $strapi): \Closure
    {
        return static fn (Database $db, string $joinTableName, array $relation, array $sourceModel): int => self::cleanComponentJoinTable($strapi, $db, $joinTableName, $relation, $sourceModel);
    }

    /**
     * @param array<string, mixed> $relation
     * @param array<string, mixed> $sourceModel
     */
    public static function cleanComponentJoinTable(Strapi $strapi, Database $db, string $joinTableName, array $relation, array $sourceModel): int
    {
        try {
            if (!$db->metadata->has((string) $relation['target'])) {
                $db->logger->debug("Target model {$relation['target']} not found, skipping {$joinTableName}");

                return 0;
            }
            $targetModel = $db->metadata->get((string) $relation['target']);

            // Check if source supports draft/publish; if it doesn't it should contain duplicate states
            $sourceContentType = $strapi->contentTypes()[$sourceModel['uid']] ?? null;
            if ($sourceContentType !== null && !($sourceContentType->options['draftAndPublish'] ?? false)) {
                return 0;
            }

            $targetContentType = $strapi->contentTypes()[(string) $relation['target']] ?? null;
            if ($targetContentType === null || !($targetContentType->options['draftAndPublish'] ?? false)) {
                return 0;
            }

            $ghostEntries = self::findPublicationStateMismatches($strapi, $db, $joinTableName, $relation, $targetModel, $sourceModel);

            if ($ghostEntries === []) {
                return 0;
            }

            $db->sql()->from($joinTableName)->whereIn('id', $ghostEntries)->delete()->run();
            $db->logger->debug('Removed ' . count($ghostEntries) . " ghost relations with publication state mismatches from {$joinTableName}");

            return count($ghostEntries);
        } catch (\Throwable $error) {
            $db->logger->error("Failed to clean join table \"{$joinTableName}\": {$error->getMessage()}");

            return 0;
        }
    }

    /** @return array{uid: string, table: string, parentId: mixed}|null */
    private static function findContentTypeParentForComponentInstance(Strapi $strapi, Components $components, Schema $componentSchema, int|string $componentId): ?array
    {
        $parentSchemas = $components->getParentSchemasForComponent($componentSchema);
        if ($parentSchemas === []) {
            return null;
        }

        $parent = $components->findComponentParent($componentSchema, $componentId, $parentSchemas);
        if ($parent === null) {
            return null;
        }

        $allComponents = $strapi->components();
        if (isset($allComponents[$parent['uid']])) {
            return self::findContentTypeParentForComponentInstance($strapi, $components, $allComponents[$parent['uid']], $parent['parentId']);
        }

        if (isset($strapi->contentTypes()[$parent['uid']])) {
            return $parent;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $relation
     * @param array<string, mixed> $targetModel
     * @param array<string, mixed> $sourceModel
     * @return list<int>
     */
    private static function findPublicationStateMismatches(Strapi $strapi, Database $db, string $joinTableName, array $relation, array $targetModel, array $sourceModel): array
    {
        try {
            $sourceColumn = $relation['joinTable']['joinColumn']['name'];
            $targetColumn = $relation['joinTable']['inverseJoinColumn']['name'];
            $targetTable = $targetModel['tableName'];
            $sql = $db->sql();
            $q = static fn (string $id): string => $sql->quoteIdentifier($id);

            $joinEntries = $db->sql()->from($joinTableName)
                ->select([
                    $db->sql()->raw($q($joinTableName) . '.' . $q('id') . ' AS join_id'),
                    $db->sql()->raw($q($joinTableName) . '.' . $q($sourceColumn) . ' AS source_id'),
                    $db->sql()->raw($q($joinTableName) . '.' . $q($targetColumn) . ' AS target_id'),
                    $db->sql()->raw($q($targetTable) . '.' . $q('published_at') . ' AS target_published_at'),
                ])
                ->leftJoin($targetTable, $targetTable, static function (\Strapi\Database\Query\SqlBuilder $join) use ($joinTableName, $targetColumn, $targetTable): void {
                    $join->on("{$joinTableName}.{$targetColumn}", "{$targetTable}.id");
                })
                ->run();
            $joinEntries = is_array($joinEntries) ? $joinEntries : [];

            $entriesBySource = [];
            foreach ($joinEntries as $entry) {
                $entriesBySource[(string) $entry['source_id']][] = $entry;
            }

            $ghostEntries = [];
            $isRelationJoinTable = str_ends_with($joinTableName, '_lnk');
            $sourceUid = (string) ($sourceModel['uid'] ?? '');
            $isComponentModel = !str_starts_with($sourceUid, 'api::') && !str_starts_with($sourceUid, 'plugin::') && str_contains($sourceUid, '.');
            $components = new Components($strapi);

            foreach ($entriesBySource as $sourceId => $entries) {
                if (count($entries) <= 1) {
                    continue;
                }

                if ($isRelationJoinTable && $isComponentModel) {
                    try {
                        $componentSchema = $strapi->components()[$sourceUid] ?? null;
                        if ($componentSchema === null) {
                            continue;
                        }
                        $parent = self::findContentTypeParentForComponentInstance($strapi, $components, $componentSchema, $sourceId);
                        if ($parent === null) {
                            continue;
                        }
                        $parentContentType = $strapi->contentTypes()[$parent['uid']] ?? null;
                        if ($parentContentType === null || !($parentContentType->options['draftAndPublish'] ?? false)) {
                            continue;
                        }
                    } catch (\Throwable) {
                        continue;
                    }
                }

                foreach ($entries as $entry) {
                    if ($entry['target_published_at'] !== null) {
                        continue;
                    }
                    // This is a draft target - find its published version
                    $draftTarget = $db->sql()->from($targetTable)->select(['document_id'])->where('id', $entry['target_id'])->limit(1)->run();
                    $draftTarget = is_array($draftTarget) ? ($draftTarget[0] ?? null) : null;
                    if ($draftTarget === null) {
                        continue;
                    }
                    $publishedVersion = $db->sql()->from($targetTable)->select(['id', 'document_id'])->where('document_id', $draftTarget['document_id'])->whereNotNull('published_at')->limit(1)->run();
                    $publishedVersion = is_array($publishedVersion) ? ($publishedVersion[0] ?? null) : null;
                    if ($publishedVersion === null) {
                        continue;
                    }
                    foreach ($entries as $e) {
                        if ((string) $e['target_id'] === (string) $publishedVersion['id']) {
                            $ghostEntries[] = (int) $e['join_id'];
                        }
                    }
                }
            }

            return array_values(array_unique($ghostEntries));
        } catch (\Throwable) {
            return [];
        }
    }
}
