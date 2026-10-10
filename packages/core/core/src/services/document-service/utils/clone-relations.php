<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Utils;

use Strapi\Core\Services\DocumentService\Transform\Relations\Utils\MapRelation;
use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\Traverse\VisitorOptions;

/**
 * Port of utils/clone-relations.ts: when cloning a document, split its data into the fields to
 * write and the join-table relations to copy row by row (keeping pivot/order columns).
 */
final class CloneRelations
{
    private const RELATION_OPERATIONS = ['connect', 'disconnect', 'set'];

    /**
     * Upstream's `prepareCloneData`: the submitted data deep-merged over the original (lodash
     * `merge`), then every meaningful relation operation payload set back at its path. A join-table
     * relation left unchanged (not submitted, or an operation payload with nothing to do, as the
     * content-manager's duplicate form sends) is left out of the create and its rows are copied
     * once the clone exists (keeps order/pivot columns).
     *
     * The original data is prepared for this port's create first: media as ids, components without
     * their ids (they are re-created), virtual relations dropped.
     *
     * @param array<string, mixed> $originalData the populated source entry (without id/createdAt/updatedAt)
     * @param array<string, mixed>|null $overrides `params.data`
     * @param callable(string): (Schema|null) $getModel
     * @return array{data: array<string, mixed>, relationsToCopy: list<string>}
     */
    public static function prepareCloneData(Strapi $strapi, array $originalData, ?array $overrides, Schema $contentType, callable $getModel): array
    {
        $submitted = $overrides ?? [];
        $operationOverrides = self::collectRelationOperationOverrides($submitted, $contentType, $getModel);

        $data = $originalData;
        foreach ($contentType->attributes as $name => $attribute) {
            $type = $attribute['type'] ?? null;
            if ($type === 'relation' && !empty($attribute['unstable_virtual'])) {
                unset($data[$name]);
            } elseif ($type === 'media' && isset($data[$name])) {
                // media is a morph relation stored on the files side: keep the ids
                $data[$name] = self::toIds($data[$name]);
            } elseif (($type === 'component' || $type === 'dynamiczone') && isset($data[$name])) {
                // components are re-created from their data (ids stripped)
                $data[$name] = self::stripComponentIds($data[$name], $attribute, $getModel);
            }
        }

        $data = self::merge($data, $submitted);
        $relationsToCopy = [];

        foreach ($contentType->attributes as $name => $attribute) {
            if (($attribute['type'] ?? null) !== 'relation' || !self::canTransformRelationOperationPayload($attribute)) {
                // join-column relations keep the merged value (its `id` wins over operations)
                continue;
            }

            $submittedValue = $submitted[$name] ?? null;
            $isOperationPayload = self::isRelationOperationPayload($submittedValue);
            $relationIsUnchanged = !array_key_exists($name, $submitted)
                || ($isOperationPayload && !self::hasMeaningfulRelationOperations($submittedValue));

            if ($relationIsUnchanged && array_key_exists($name, $originalData) && empty($attribute['unstable_virtual'])) {
                unset($data[$name]);
                $relationsToCopy[] = (string) $name;
            }
        }

        foreach ($operationOverrides as $path => $value) {
            $data = self::setPath($data, explode('.', $path), $value);
        }

        return ['data' => $data, 'relationsToCopy' => $relationsToCopy];
    }

    private static function isRelationOperationPayload(mixed $value): bool
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return false;
        }
        foreach (self::RELATION_OPERATIONS as $operation) {
            if (array_key_exists($operation, $value)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $value */
    private static function hasMeaningfulRelationOperations(array $value): bool
    {
        if (array_key_exists('set', $value)) {
            return true;
        }
        foreach (['connect', 'disconnect'] as $operation) {
            $operationValue = $value[$operation] ?? null;
            if (is_array($operationValue) && array_is_list($operationValue) ? $operationValue !== [] : $operationValue !== null) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $attribute */
    private static function canTransformRelationOperationPayload(array $attribute): bool
    {
        return ($attribute['useJoinTable'] ?? null) !== false && ($attribute['relation'] ?? null) !== 'morphToOne';
    }

    /**
     * The meaningful relation operation payloads of the submitted data, by path (`tag`, `details.tag`,
     * `blocks.0.tag`), sorted by path.
     *
     * @param array<string, mixed> $submitted
     * @param callable(string): (Schema|null) $getModel
     * @return array<string, array<string, mixed>>
     */
    private static function collectRelationOperationOverrides(array $submitted, Schema $contentType, callable $getModel): array
    {
        $overrides = [];
        MapRelation::traverseEntityRelations(
            static function (VisitorOptions $options) use (&$overrides): void {
                if (self::isRelationOperationPayload($options->value) && is_array($options->value) && self::hasMeaningfulRelationOperations($options->value)) {
                    $overrides[(string) $options->path->rawWithIndices] = $options->value;
                }
            },
            ['schema' => $contentType, 'getModel' => $getModel],
            $submitted,
        );
        ksort($overrides, SORT_STRING);

        return $overrides;
    }

    /**
     * lodash's `merge`: objects merged key by key and arrays index by index, recursively; a source
     * value replaces anything that is not mergeable with it.
     */
    private static function merge(mixed $target, mixed $source): mixed
    {
        if (!is_array($target) || !is_array($source)) {
            return $source;
        }
        if ($source !== [] && $target !== [] && array_is_list($source) !== array_is_list($target)) {
            return $source;
        }
        foreach ($source as $key => $value) {
            $target[$key] = array_key_exists($key, $target) ? self::merge($target[$key], $value) : $value;
        }

        return $target;
    }

    /**
     * lodash's `set` for a dotted path.
     *
     * @param list<string> $path
     */
    private static function setPath(mixed $data, array $path, mixed $value): mixed
    {
        if ($path === []) {
            return $value;
        }
        $key = array_shift($path);
        $data = is_array($data) ? $data : [];
        $data[$key] = self::setPath($data[$key] ?? null, $path, $value);

        return $data;
    }

    private static function toIds(mixed $value): mixed
    {
        if (is_array($value) && array_is_list($value)) {
            return array_map(static fn (mixed $v): mixed => is_array($v) ? ($v['id'] ?? null) : $v, $value);
        }

        return is_array($value) ? ($value['id'] ?? null) : $value;
    }

    /**
     * A component or dynamic-zone value without the ids of its components (nested ones too);
     * relation and media values inside keep theirs.
     *
     * @param array<string, mixed> $attribute
     * @param callable(string): (Schema|null) $getModel
     */
    private static function stripComponentIds(mixed $value, array $attribute, callable $getModel): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::stripComponentIds($item, $attribute, $getModel), $value);
        }

        unset($value['id'], $value['documentId']);
        $uid = ($attribute['type'] ?? null) === 'dynamiczone' ? ($value['__component'] ?? null) : ($attribute['component'] ?? null);
        $schema = is_string($uid) ? $getModel($uid) : null;
        if ($schema === null) {
            return $value;
        }
        foreach ($schema->attributes as $name => $nested) {
            $type = $nested['type'] ?? null;
            if (($type === 'component' || $type === 'dynamiczone') && isset($value[$name])) {
                $value[$name] = self::stripComponentIds($value[$name], $nested, $getModel);
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
