<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema as StrapiSchema;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of src/utils/components.ts: the component CRUD helpers the data transfer uses on raw
 * entities (`strapi.db.query`). Upstream reads the global `strapi`; the instance is passed in.
 *
 * Concurrency (`async.map` with MySQL's concurrency of 1) does not apply: everything runs in order.
 */
final class Components
{
    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function omitComponentData(StrapiSchema $contentType, array $data): array
    {
        foreach ($contentType->attributes as $attributeName => $attribute) {
            if (ContentTypes::isComponentAttribute($attribute)) {
                unset($data[$attributeName]);
            }
        }

        return $data;
    }

    private static function model(Strapi $strapi, string $uid): StrapiSchema
    {
        return $strapi->getModel($uid) ?? throw new \RuntimeException("Model {$uid} not found");
    }

    /**
     * NOTE: we could generalize the logic to allow CRUD of relation directly in the DB layer
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function createComponents(Strapi $strapi, string $uid, array $data): array
    {
        $attributes = self::model($strapi, $uid)->attributes;

        $componentBody = [];

        foreach ($attributes as $attributeName => $attribute) {
            if (!array_key_exists($attributeName, $data) || !ContentTypes::isComponentAttribute($attribute)) {
                continue;
            }

            if (($attribute['type'] ?? null) === 'component') {
                $componentUID = (string) $attribute['component'];
                $repeatable = ($attribute['repeatable'] ?? false) === true;

                $componentValue = $data[$attributeName];

                if ($componentValue === null) {
                    continue;
                }

                if ($repeatable) {
                    if (!is_array($componentValue) || !array_is_list($componentValue)) {
                        throw new \RuntimeException('Expected an array to create repeatable component');
                    }

                    $components = array_map(static fn (mixed $value): array => self::createComponent($strapi, $componentUID, is_array($value) ? $value : []), $componentValue);

                    $componentBody[$attributeName] = array_map(static fn (array $c): array => [
                        'id' => $c['id'],
                        '__pivot' => [
                            'field' => $attributeName,
                            'component_type' => $componentUID,
                        ],
                    ], $components);
                } else {
                    $component = self::createComponent($strapi, $componentUID, is_array($componentValue) ? $componentValue : []);
                    $componentBody[$attributeName] = [
                        'id' => $component['id'],
                        '__pivot' => [
                            'field' => $attributeName,
                            'component_type' => $componentUID,
                        ],
                    ];
                }

                continue;
            }

            if (($attribute['type'] ?? null) === 'dynamiczone') {
                $dynamiczoneValues = $data[$attributeName];

                if (!is_array($dynamiczoneValues) || !array_is_list($dynamiczoneValues)) {
                    throw new \RuntimeException('Expected an array to create repeatable component');
                }

                $componentBody[$attributeName] = array_map(static function (mixed $value) use ($strapi, $attributeName): array {
                    $value = is_array($value) ? $value : [];
                    $componentUID = (string) ($value['__component'] ?? '');
                    $created = self::createComponent($strapi, $componentUID, $value);

                    return [
                        'id' => $created['id'],
                        '__component' => $componentUID,
                        '__pivot' => [
                            'field' => $attributeName,
                        ],
                    ];
                }, $dynamiczoneValues);

                continue;
            }
        }

        return $componentBody;
    }

    /**
     * @param array<string, mixed> $entity
     *
     * @return array<string, mixed>
     */
    public static function getComponents(Strapi $strapi, string $uid, array $entity): array
    {
        $componentAttributes = ContentTypes::getComponentAttributes(self::model($strapi, $uid));

        if ($componentAttributes === []) {
            return [];
        }

        $loaded = $strapi->db()->query($uid)->load($entity, $componentAttributes);

        return is_array($loaded) ? $loaded : [];
    }

    /**
     * delete old components, create or update
     *
     * @param array<string, mixed> $entityToUpdate
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function updateComponents(Strapi $strapi, string $uid, array $entityToUpdate, array $data): array
    {
        $attributes = self::model($strapi, $uid)->attributes;

        $componentBody = [];

        foreach ($attributes as $attributeName => $attribute) {
            if (!array_key_exists($attributeName, $data)) {
                continue;
            }

            if (($attribute['type'] ?? null) === 'component') {
                $componentUID = (string) $attribute['component'];
                $repeatable = ($attribute['repeatable'] ?? false) === true;

                $componentValue = $data[$attributeName];

                self::deleteOldComponents($strapi, $uid, $componentUID, $entityToUpdate, $attributeName, $componentValue);

                if ($repeatable) {
                    if (!is_array($componentValue) || !array_is_list($componentValue)) {
                        throw new \RuntimeException('Expected an array to create repeatable component');
                    }

                    $components = array_map(static fn (mixed $value): ?array => self::updateOrCreateComponent($strapi, $componentUID, is_array($value) ? $value : null), $componentValue);

                    $componentBody[$attributeName] = array_values(array_map(static fn (array $c): array => [
                        'id' => $c['id'],
                        '__pivot' => [
                            'field' => $attributeName,
                            'component_type' => $componentUID,
                        ],
                    ], array_filter($components, static fn (?array $c): bool => $c !== null)));
                } else {
                    $component = self::updateOrCreateComponent($strapi, $componentUID, is_array($componentValue) ? $componentValue : null);
                    $componentBody[$attributeName] = $component === null ? null : [
                        'id' => $component['id'],
                        '__pivot' => [
                            'field' => $attributeName,
                            'component_type' => $componentUID,
                        ],
                    ];
                }

                continue;
            }

            if (($attribute['type'] ?? null) === 'dynamiczone') {
                $dynamiczoneValues = $data[$attributeName];

                self::deleteOldDZComponents($strapi, $uid, $entityToUpdate, $attributeName, $dynamiczoneValues);

                if (!is_array($dynamiczoneValues) || !array_is_list($dynamiczoneValues)) {
                    throw new \RuntimeException('Expected an array to create repeatable component');
                }

                $componentBody[$attributeName] = array_map(static function (mixed $value) use ($strapi, $attributeName): array {
                    $value = is_array($value) ? $value : [];
                    $componentUID = (string) ($value['__component'] ?? '');
                    $component = self::updateOrCreateComponent($strapi, $componentUID, $value) ?? [];

                    return [
                        'id' => $component['id'] ?? null,
                        '__component' => $componentUID,
                        '__pivot' => [
                            'field' => $attributeName,
                        ],
                    ];
                }, $dynamiczoneValues);

                continue;
            }
        }

        return $componentBody;
    }

    private static function pickStringifiedId(mixed $value): string
    {
        $id = is_array($value) ? ($value['id'] ?? null) : null;

        return is_scalar($id) ? (string) $id : '';
    }

    /** @return list<mixed> lodash `castArray` */
    private static function castArray(mixed $value): array
    {
        if (is_array($value) && array_is_list($value)) {
            return $value;
        }

        return [$value];
    }

    /** @param array<string, mixed> $entityToUpdate */
    private static function deleteOldComponents(Strapi $strapi, string $uid, string $componentUID, array $entityToUpdate, string $attributeName, mixed $componentValue): void
    {
        $previousValue = $strapi->db()->query($uid)->load($entityToUpdate, $attributeName);

        $hasId = static fn (mixed $v): bool => is_array($v) && array_key_exists('id', $v);
        $idsToKeep = array_map(self::pickStringifiedId(...), array_values(array_filter(self::castArray($componentValue), $hasId)));
        $allIds = array_map(self::pickStringifiedId(...), array_values(array_filter(self::castArray($previousValue), $hasId)));

        foreach ($idsToKeep as $id) {
            if (!in_array($id, $allIds, true)) {
                throw new ApplicationError("Some of the provided components in {$attributeName} are not related to the entity");
            }
        }

        foreach (array_diff($allIds, $idsToKeep) as $idToDelete) {
            self::deleteComponent($strapi, $componentUID, ['id' => $idToDelete]);
        }
    }

    /** @param array<string, mixed> $entityToUpdate */
    private static function deleteOldDZComponents(Strapi $strapi, string $uid, array $entityToUpdate, string $attributeName, mixed $dynamiczoneValues): void
    {
        $previousValue = $strapi->db()->query($uid)->load($entityToUpdate, $attributeName);

        $hasId = static fn (mixed $v): bool => is_array($v) && array_key_exists('id', $v);
        $toRef = static fn (mixed $v): array => ['id' => self::pickStringifiedId($v), '__component' => is_array($v) ? ($v['__component'] ?? null) : null];

        $idsToKeep = array_map($toRef, array_values(array_filter(self::castArray($dynamiczoneValues), $hasId)));
        $allIds = array_map($toRef, array_values(array_filter(self::castArray($previousValue), $hasId)));

        foreach ($idsToKeep as $keep) {
            $found = false;
            foreach ($allIds as $el) {
                if ($el['id'] === $keep['id'] && $el['__component'] === $keep['__component']) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $err = new ApplicationError("Some of the provided components in {$attributeName} are not related to the entity");
                $err->status = 400;
                throw $err;
            }
        }

        foreach ($allIds as $ref) {
            $kept = false;
            foreach ($idsToKeep as $el) {
                if ($el['id'] === $ref['id'] && $el['__component'] === $ref['__component']) {
                    $kept = true;
                    break;
                }
            }
            if (!$kept) {
                self::deleteComponent($strapi, (string) $ref['__component'], ['id' => $ref['id']]);
            }
        }
    }

    /**
     * @param array<string, mixed> $entityToDelete
     * @param array{loadComponents?: bool} $options
     */
    public static function deleteComponents(Strapi $strapi, string $uid, array $entityToDelete, array $options = []): void
    {
        $loadComponents = $options['loadComponents'] ?? true;
        $attributes = self::model($strapi, $uid)->attributes;

        foreach ($attributes as $attributeName => $attribute) {
            $type = $attribute['type'] ?? null;
            if ($type !== 'component' && $type !== 'dynamiczone') {
                continue;
            }

            $value = $loadComponents
                ? $strapi->db()->query($uid)->load($entityToDelete, $attributeName)
                : ($entityToDelete[$attributeName] ?? null);

            if (!$value) {
                continue;
            }

            if ($type === 'component') {
                $componentUID = (string) $attribute['component'];
                foreach (self::castArray($value) as $subValue) {
                    if (is_array($subValue)) {
                        self::deleteComponent($strapi, $componentUID, $subValue);
                    }
                }
            } else {
                // delete dynamic zone components
                foreach (self::castArray($value) as $subValue) {
                    if (is_array($subValue)) {
                        self::deleteComponent($strapi, (string) ($subValue['__component'] ?? ''), $subValue);
                    }
                }
            }
        }
    }

    /**
     * components can have nested compos so this must be recursive
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function createComponent(Strapi $strapi, string $uid, array $data): array
    {
        $model = self::model($strapi, $uid);

        $componentData = self::createComponents($strapi, $uid, $data);
        // Make sure we don't save the component with a pre-defined ID
        unset($data['id']);
        // Remove the component data from the original data object and assign the newly created component instead
        $transformed = array_merge(self::omitComponentData($model, $data), $componentData);

        return $strapi->db()->query($uid)->create(['data' => $transformed]);
    }

    /**
     * components can have nested compos so this must be recursive
     *
     * @param array<string, mixed> $componentToUpdate
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    public static function updateComponent(Strapi $strapi, string $uid, array $componentToUpdate, array $data): ?array
    {
        $model = self::model($strapi, $uid);

        $componentData = self::updateComponents($strapi, $uid, $componentToUpdate, $data);

        return $strapi->db()->query($uid)->update([
            'where' => [
                'id' => $componentToUpdate['id'] ?? null,
            ],
            'data' => array_merge(self::omitComponentData($model, $data), $componentData),
        ]);
    }

    /**
     * @param array<string, mixed>|null $value
     *
     * @return array<string, mixed>|null
     */
    public static function updateOrCreateComponent(Strapi $strapi, string $componentUID, ?array $value): ?array
    {
        if ($value === null) {
            return null;
        }

        // update
        if (array_key_exists('id', $value) && $value['id'] !== null) {
            // TODO: verify the compo is associated with the entity
            return self::updateComponent($strapi, $componentUID, ['id' => $value['id']], $value);
        }

        // create
        return self::createComponent($strapi, $componentUID, $value);
    }

    /** @param array<string, mixed> $componentToDelete */
    public static function deleteComponent(Strapi $strapi, string $uid, array $componentToDelete): void
    {
        self::deleteComponents($strapi, $uid, $componentToDelete);
        $strapi->db()->query($uid)->delete(['where' => ['id' => $componentToDelete['id'] ?? null]]);
    }

    /**
     * Walk the source data and the newly created entity in parallel and collect
     * the [old ID, new ID] couple of every component instance found in both trees
     * (components & dynamic zones, including nested ones).
     *
     * Unlike a JSON diff, couples are also collected when the ID did not change,
     * so the result is an exhaustive map of every component instance that was
     * re-created with the entity.
     *
     * @param array{data: mixed, created: mixed, schema: StrapiSchema, strapi: Strapi} $params
     *
     * @return list<array{uid: string, oldID: int, newID: int}>
     */
    public static function collectComponentIdMappings(array $params): array
    {
        ['data' => $data, 'created' => $created, 'schema' => $schema, 'strapi' => $strapi] = $params;

        $mappings = [];

        self::visitSchema($strapi, $schema, $data, $created, $mappings);

        return $mappings;
    }

    /** @param list<array{uid: string, oldID: int, newID: int}> $mappings */
    private static function visitComponent(Strapi $strapi, string $uid, mixed $oldValue, mixed $newValue, array &$mappings): void
    {
        if (!is_array($oldValue) || !is_array($newValue)) {
            return;
        }

        if (is_int($oldValue['id'] ?? null) && is_int($newValue['id'] ?? null)) {
            $mappings[] = ['uid' => $uid, 'oldID' => $oldValue['id'], 'newID' => $newValue['id']];
        }

        $componentSchema = $strapi->getModel($uid);

        // Collect nested components & dynamic zones
        if ($componentSchema !== null) {
            self::visitSchema($strapi, $componentSchema, $oldValue, $newValue, $mappings);
        }
    }

    /** @param list<array{uid: string, oldID: int, newID: int}> $mappings */
    private static function visitSchema(Strapi $strapi, StrapiSchema $currentSchema, mixed $oldData, mixed $newData, array &$mappings): void
    {
        if (!is_array($oldData) || !is_array($newData)) {
            return;
        }

        foreach ($currentSchema->attributes as $attributeName => $attribute) {
            $oldValue = $oldData[$attributeName] ?? null;
            $newValue = $newData[$attributeName] ?? null;

            if ($oldValue === null || $newValue === null) {
                continue;
            }

            $type = $attribute['type'] ?? null;

            if ($type === 'component') {
                $componentUID = (string) $attribute['component'];

                if (($attribute['repeatable'] ?? false) === true) {
                    if (!is_array($oldValue) || !array_is_list($oldValue) || !is_array($newValue) || !array_is_list($newValue)) {
                        continue;
                    }

                    // Components are created (and thus populated back) in the same
                    // order as the source data, pair them by index
                    $length = min(count($oldValue), count($newValue));

                    for ($i = 0; $i < $length; ++$i) {
                        self::visitComponent($strapi, $componentUID, $oldValue[$i], $newValue[$i], $mappings);
                    }
                } else {
                    self::visitComponent($strapi, $componentUID, $oldValue, $newValue, $mappings);
                }
            }

            if ($type === 'dynamiczone') {
                if (!is_array($oldValue) || !array_is_list($oldValue) || !is_array($newValue) || !array_is_list($newValue)) {
                    continue;
                }

                $length = min(count($oldValue), count($newValue));

                for ($i = 0; $i < $length; ++$i) {
                    $oldItem = $oldValue[$i];
                    $newItem = $newValue[$i];
                    $componentUID = is_array($oldItem) ? ($oldItem['__component'] ?? null) : null;

                    // Both items must reference the same dynamic zone component for the
                    // ID couple to be meaningful
                    if (!is_string($componentUID) || $componentUID === '') {
                        continue;
                    }
                    $newComponent = is_array($newItem) ? ($newItem['__component'] ?? null) : null;
                    if ($newComponent && $newComponent !== $componentUID) {
                        continue;
                    }

                    self::visitComponent($strapi, $componentUID, $oldItem, $newItem, $mappings);
                }
            }
        }
    }

    /**
     * Resolve the component UID of an entity's attribute based
     * on a given path (components & dynamic zones only)
     *
     * @param array{paths: list<string>, strapi: Strapi, data: mixed, contentType: StrapiSchema} $params
     */
    public static function resolveComponentUID(array $params): ?string
    {
        ['paths' => $paths, 'strapi' => $strapi, 'data' => $data, 'contentType' => $contentType] = $params;

        $value = $data;
        /** @var StrapiSchema|\Closure(mixed): ?StrapiSchema|null $cType */
        $cType = $contentType;
        foreach ($paths as $path) {
            $value = is_array($value) ? ($value[$path] ?? null) : null;

            // Needed when the value of cType should be computed
            // based on the next value (eg: dynamic zones)
            if ($cType instanceof \Closure) {
                $cType = $cType($value);
            }

            if ($cType instanceof StrapiSchema && array_key_exists($path, $cType->attributes)) {
                $attribute = $cType->attributes[$path];

                if (($attribute['type'] ?? null) === 'component') {
                    $cType = $strapi->getModel((string) $attribute['component']);
                }

                if (($attribute['type'] ?? null) === 'dynamiczone') {
                    $cType = static fn (mixed $v): ?StrapiSchema => is_array($v) && is_string($v['__component'] ?? null) ? $strapi->getModel($v['__component']) : null;
                }
            }
        }

        if ($cType instanceof StrapiSchema) {
            return $cType->uid;
        }

        return null;
    }
}
