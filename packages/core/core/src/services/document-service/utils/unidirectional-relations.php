<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Utils;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;

/**
 * Port of utils/unidirectional-relations.ts: unidirectional relations targeting the entries being
 * replaced (publish / discard) are loaded before deletion and re-created pointing at the new versions.
 *
 * @phpstan-type RelationUpdate array{joinTable: array<string, mixed>, relations: list<array<string, mixed>>}
 */
final class UnidirectionalRelations
{
    /**
     * @param array{oldVersions: list<array<string, mixed>>, newVersions: list<array<string, mixed>>} $context
     * @param array{shouldPropagateRelation?: callable(array<string, mixed>, Schema): bool} $options
     * @return list<RelationUpdate>
     */
    public static function load(Strapi $strapi, string $uid, array $context, array $options = []): array
    {
        $oldVersions = $context['oldVersions'];
        $newVersions = $context['newVersions'];
        $updates = [];
        $idColumn = \Strapi\Database\Utils\Identifiers\Identifiers::ID_COLUMN;

        $strapi->db()->transaction(static function () use ($strapi, $uid, $oldVersions, $newVersions, $options, &$updates, $idColumn): void {
            $models = [...array_values($strapi->contentTypes()), ...array_values($strapi->components())];

            foreach ($models as $model) {
                $dbModel = $strapi->db()->metadata->get($model->uid);

                foreach ($dbModel['attributes'] as $attribute) {
                    // Only consider unidirectional relations: bidirectional ones are handled by bidirectionalRelations,
                    // self-referential rows where both sides are republished by selfReferentialRelations.
                    if (($attribute['type'] ?? null) !== 'relation' || ($attribute['target'] ?? null) !== $uid || !empty($attribute['inversedBy']) || !empty($attribute['mappedBy'])) {
                        continue;
                    }

                    // TODO: joinColumn relations
                    $joinTable = $attribute['joinTable'] ?? null;
                    if (!is_array($joinTable)) {
                        continue;
                    }

                    $sourceColumnName = $joinTable['joinColumn']['name'];
                    $targetColumnName = $joinTable['inverseJoinColumn']['name'];

                    // Load all relations that need to be updated
                    $ids = array_map(static fn (array $entry): mixed => $entry['id'], $oldVersions);

                    // For self-referential relations, exclude join rows where the source entry is also being republished
                    $oldVersionsRelations = RelationSyncHelpers::selectWhereIn(
                        $strapi,
                        $joinTable['name'],
                        $targetColumnName,
                        $ids,
                        $model->uid === $uid ? $sourceColumnName : null,
                        $model->uid === $uid ? $ids : [],
                    );

                    if ($oldVersionsRelations !== []) {
                        $updates[] = ['joinTable' => $joinTable, 'relations' => $oldVersionsRelations];
                    }

                    /**
                     * if publishing: if published version exists update published links, else create link to the new version
                     * if discarding: if published version link exists & not draft version link, create link to new draft version
                     */
                    if (!($model->options['draftAndPublish'] ?? false)) {
                        $newIds = array_map(static fn (array $entry): mixed => $entry['id'], $newVersions);

                        $newVersionsRelations = RelationSyncHelpers::selectWhereIn($strapi, $joinTable['name'], $targetColumnName, $newIds);

                        $versionRelations = $newVersionsRelations;
                        if (isset($options['shouldPropagateRelation'])) {
                            $versionRelations = [];
                            foreach ($newVersionsRelations as $relation) {
                                if ($options['shouldPropagateRelation']($relation, $model)) {
                                    $versionRelations[] = $relation;
                                }
                            }
                        }

                        if ($versionRelations !== []) {
                            // when publishing a draft that doesn't have a published version yet, copy the links to the
                            // draft over to the published version; when discarding a published version, if no drafts exist
                            $discardToAdd = [];
                            foreach ($versionRelations as $relation) {
                                $matching = false;
                                foreach ($oldVersionsRelations as $oldRelation) {
                                    if ($oldRelation[$sourceColumnName] == $relation[$sourceColumnName]) {
                                        $matching = true;
                                        break;
                                    }
                                }
                                if (!$matching) {
                                    $discardToAdd[] = RelationSyncHelpers::omitId($relation, $idColumn);
                                }
                            }

                            $updates[] = ['joinTable' => $joinTable, 'relations' => $discardToAdd];
                        }
                    }
                }
            }
        });

        return $updates;
    }

    /**
     * Re-create the loaded relations so they target the new entries.
     *
     * @param list<array<string, mixed>> $oldEntries
     * @param list<array<string, mixed>> $newEntries
     * @param list<RelationUpdate> $oldRelations
     */
    public static function sync(Strapi $strapi, array $oldEntries, array $newEntries, array $oldRelations): void
    {
        $newEntryByLocale = [];
        foreach ($newEntries as $entry) {
            $newEntryByLocale[(string) ($entry['locale'] ?? '')] = $entry;
        }
        $oldEntriesMap = [];
        foreach ($oldEntries as $entry) {
            $newEntry = $newEntryByLocale[(string) ($entry['locale'] ?? '')] ?? null;
            if ($newEntry === null) {
                continue;
            }
            $oldEntriesMap[(string) $entry['id']] = $newEntry['id'];
        }

        $strapi->db()->transaction(static function () use ($strapi, $oldRelations, $oldEntriesMap): void {
            foreach ($oldRelations as ['joinTable' => $joinTable, 'relations' => $relations]) {
                $column = $joinTable['inverseJoinColumn']['name'];

                $newRelations = [];
                foreach ($relations as $relation) {
                    $newId = $oldEntriesMap[(string) $relation[$column]] ?? null;
                    if ($newId === null) {
                        continue;
                    }
                    $newRelations[] = [...$relation, $column => $newId];
                }

                RelationSyncHelpers::batchInsert($strapi, $joinTable['name'], $newRelations);
            }
        });
    }
}
