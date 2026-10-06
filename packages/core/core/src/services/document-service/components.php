<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService;

use Strapi\Core\Strapi;
use Strapi\Database\Utils\SchemaFactory;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of services/document-service/components.ts: create/update/delete the component rows of an
 * entry and build the `{ id, __pivot }` links the database layer stores in `<table>_cmps`.
 */
final class Components
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * @param Schema|array<string, mixed> $schema
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function omitComponentData(Schema|array $schema, array $data): array
    {
        $attributes = ContentTypes::attributes($schema);
        foreach ($attributes as $name => $attribute) {
            if (ContentTypes::isComponentAttribute($attribute)) {
                unset($data[$name]);
            }
        }

        return $data;
    }

    /**
     * @param Schema|array<string, mixed> $schema
     * @param array<string, mixed> $componentData
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function assignComponentData(Schema|array $schema, array $componentData, array $data): array
    {
        return [...$componentData, ...self::omitComponentData($schema, $data)];
    }

    /** @return array<string, array<string, mixed>> */
    private function attributesOf(string $uid): array
    {
        $model = $this->strapi->getModel($uid);

        return $model?->attributes ?? [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createComponents(string $uid, array $data): array
    {
        $attributes = $this->attributesOf($uid);
        $componentBody = [];

        foreach ($attributes as $attributeName => $attribute) {
            if (!array_key_exists($attributeName, $data) || !ContentTypes::isComponentAttribute($attribute)) {
                continue;
            }

            if ($attribute['type'] === 'component') {
                $componentUID = (string) $attribute['component'];
                $repeatable = (bool) ($attribute['repeatable'] ?? false);
                $componentValue = $data[$attributeName];

                if ($componentValue === null) {
                    continue;
                }

                if ($repeatable) {
                    if (!is_array($componentValue) || !array_is_list($componentValue)) {
                        throw new \RuntimeException('Expected an array to create repeatable component');
                    }

                    $components = array_map(fn (mixed $value): array => $this->createComponent($componentUID, is_array($value) ? $value : []), $componentValue);

                    $componentBody[$attributeName] = array_map(static fn (array $component): array => [
                        'id' => $component['id'],
                        '__pivot' => ['field' => $attributeName, 'component_type' => $componentUID],
                    ], $components);
                } else {
                    $component = $this->createComponent($componentUID, is_array($componentValue) ? $componentValue : []);

                    $componentBody[$attributeName] = [
                        'id' => $component['id'],
                        '__pivot' => ['field' => $attributeName, 'component_type' => $componentUID],
                    ];
                }

                continue;
            }

            if ($attribute['type'] === 'dynamiczone') {
                $dynamiczoneValues = $data[$attributeName];

                if (!is_array($dynamiczoneValues) || !array_is_list($dynamiczoneValues)) {
                    throw new \RuntimeException('Expected an array to create repeatable component');
                }

                $componentBody[$attributeName] = array_map(function (mixed $value) use ($attributeName): array {
                    $value = is_array($value) ? $value : [];
                    $component = $this->createComponent((string) $value['__component'], $value);

                    return ['id' => $component['id'], '__component' => $value['__component'], '__pivot' => ['field' => $attributeName]];
                }, $dynamiczoneValues);
            }
        }

        return $componentBody;
    }

    /**
     * @param array{id: mixed} $entity
     * @return array<string, mixed>
     */
    public function getComponents(string $uid, array $entity): array
    {
        $model = $this->strapi->getModel($uid);
        $componentAttributes = $model === null ? [] : ContentTypes::getComponentAttributes($model);

        if ($componentAttributes === []) {
            return [];
        }

        $loaded = $this->strapi->db()->query($uid)->load($entity, $componentAttributes);

        return is_array($loaded) ? $loaded : [];
    }

    /**
     * delete old components, create or update.
     *
     * @param array{id: mixed} $entityToUpdate
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateComponents(string $uid, array $entityToUpdate, array $data): array
    {
        $attributes = $this->attributesOf($uid);
        $componentBody = [];

        foreach ($attributes as $attributeName => $attribute) {
            if (!array_key_exists($attributeName, $data)) {
                continue;
            }

            if (($attribute['type'] ?? null) === 'component') {
                $componentUID = (string) $attribute['component'];
                $repeatable = (bool) ($attribute['repeatable'] ?? false);
                $componentValue = $data[$attributeName];

                $this->deleteOldComponents($uid, $componentUID, $entityToUpdate, $attributeName, $componentValue);

                if ($repeatable) {
                    if (!is_array($componentValue) || !array_is_list($componentValue)) {
                        throw new \RuntimeException('Expected an array to create repeatable component');
                    }

                    $components = array_map(fn (mixed $value): ?array => $this->updateOrCreateComponent($componentUID, $value), $componentValue);

                    $componentBody[$attributeName] = array_values(array_map(static fn (array $component): array => [
                        'id' => $component['id'],
                        '__pivot' => ['field' => $attributeName, 'component_type' => $componentUID],
                    ], array_filter($components, static fn (?array $c): bool => $c !== null)));
                } else {
                    $component = $this->updateOrCreateComponent($componentUID, $componentValue);
                    $componentBody[$attributeName] = $component === null ? null : [
                        'id' => $component['id'],
                        '__pivot' => ['field' => $attributeName, 'component_type' => $componentUID],
                    ];
                }
            } elseif (($attribute['type'] ?? null) === 'dynamiczone') {
                $dynamiczoneValues = $data[$attributeName];

                $this->deleteOldDZComponents($uid, $entityToUpdate, $attributeName, $dynamiczoneValues);

                if (!is_array($dynamiczoneValues) || !array_is_list($dynamiczoneValues)) {
                    throw new \RuntimeException('Expected an array to create repeatable component');
                }

                $componentBody[$attributeName] = array_map(function (mixed $value) use ($attributeName): array {
                    $value = is_array($value) ? $value : [];
                    $component = $this->updateOrCreateComponent((string) $value['__component'], $value);

                    return ['id' => $component['id'] ?? null, '__component' => $value['__component'], '__pivot' => ['field' => $attributeName]];
                }, $dynamiczoneValues);
            }
        }

        return $componentBody;
    }

    private static function pickStringifiedId(mixed $value): string
    {
        return (string) (is_array($value) ? $value['id'] : $value);
    }

    /** @param array{id: mixed} $entityToUpdate */
    private function deleteOldComponents(string $uid, string $componentUID, array $entityToUpdate, string $attributeName, mixed $componentValue): void
    {
        $previousValue = $this->strapi->db()->query($uid)->load($entityToUpdate, $attributeName);

        $toList = static fn (mixed $v): array => $v === null ? [] : (is_array($v) && array_is_list($v) ? $v : [$v]);
        $withId = static fn (mixed $v): bool => is_array($v) && array_key_exists('id', $v) && $v['id'] !== null;

        $idsToKeep = array_map(self::pickStringifiedId(...), array_filter($toList($componentValue), $withId));
        $allIds = array_map(self::pickStringifiedId(...), array_filter($toList($previousValue), $withId));

        foreach ($idsToKeep as $id) {
            if (!in_array($id, $allIds, true)) {
                throw new ApplicationError("Some of the provided components in {$attributeName} are not related to the entity");
            }
        }

        $idsToDelete = array_diff($allIds, $idsToKeep);

        foreach ($idsToDelete as $idToDelete) {
            $this->deleteComponent($componentUID, ['id' => $idToDelete]);
        }
    }

    /** @param array{id: mixed} $entityToUpdate */
    private function deleteOldDZComponents(string $uid, array $entityToUpdate, string $attributeName, mixed $dynamiczoneValues): void
    {
        $previousValue = $this->strapi->db()->query($uid)->load($entityToUpdate, $attributeName);

        $toList = static fn (mixed $v): array => $v === null ? [] : (is_array($v) && array_is_list($v) ? $v : [$v]);
        $withId = static fn (mixed $v): bool => is_array($v) && array_key_exists('id', $v) && $v['id'] !== null;
        $toRef = static fn (array $v): array => ['id' => self::pickStringifiedId($v), '__component' => $v['__component'] ?? null];

        $idsToKeep = array_map($toRef, array_filter($toList($dynamiczoneValues), $withId));
        $allIds = array_map($toRef, array_filter($toList($previousValue), $withId));

        $has = static fn (array $list, array $ref): bool => array_filter($list, static fn (array $el): bool => $el['id'] === $ref['id'] && $el['__component'] === $ref['__component']) !== [];

        foreach ($idsToKeep as $ref) {
            if (!$has($allIds, $ref)) {
                throw new ApplicationError("Some of the provided components in {$attributeName} are not related to the entity");
            }
        }

        foreach ($allIds as $ref) {
            if (!$has($idsToKeep, $ref)) {
                $this->deleteComponent((string) $ref['__component'], ['id' => $ref['id']]);
            }
        }
    }

    /**
     * @param array<string, mixed> $entityToDelete
     * @param array{loadComponents?: bool} $options
     */
    public function deleteComponents(string $uid, array $entityToDelete, array $options = []): void
    {
        $loadComponents = $options['loadComponents'] ?? true;
        $attributes = $this->attributesOf($uid);

        foreach ($attributes as $attributeName => $attribute) {
            $type = $attribute['type'] ?? null;
            if ($type !== 'component' && $type !== 'dynamiczone') {
                continue;
            }

            $value = $loadComponents
                ? $this->strapi->db()->query($uid)->load($entityToDelete, $attributeName)
                : ($entityToDelete[$attributeName] ?? null);

            if (!$value) {
                continue;
            }

            $list = is_array($value) && array_is_list($value) ? $value : [$value];
            if ($type === 'component') {
                foreach ($list as $subValue) {
                    $this->deleteComponent((string) $attribute['component'], is_array($subValue) ? $subValue : ['id' => $subValue]);
                }
            } else {
                foreach ($list as $subValue) {
                    $this->deleteComponent((string) $subValue['__component'], $subValue);
                }
            }
        }
    }

    /* Component queries */

    /**
     * Components can have nested components so this must be recursive.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createComponent(string $uid, array $data): array
    {
        $schema = $this->strapi->getModel($uid);
        if ($schema === null) {
            throw new \RuntimeException("Component {$uid} not found");
        }

        $componentData = $this->createComponents($uid, $data);

        // Make sure we don't save the component with a pre-defined ID.
        unset($data['id']);
        $entryData = self::assignComponentData($schema, $componentData, $data);

        return $this->strapi->db()->query($uid)->create(['data' => $entryData]);
    }

    /**
     * @param array{id: mixed} $componentToUpdate
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function updateComponent(string $uid, array $componentToUpdate, array $data): ?array
    {
        $schema = $this->strapi->getModel($uid);
        if ($schema === null) {
            throw new \RuntimeException("Component {$uid} not found");
        }

        $componentData = $this->updateComponents($uid, $componentToUpdate, $data);

        return $this->strapi->db()->query($uid)->update([
            'where' => ['id' => $componentToUpdate['id']],
            'data' => self::assignComponentData($schema, $componentData, $data),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function updateOrCreateComponent(string $componentUID, mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $value = is_array($value) ? $value : [];

        // update
        if (array_key_exists('id', $value) && $value['id'] !== null) {
            // TODO: verify the compo is associated with the entity
            return $this->updateComponent($componentUID, ['id' => $value['id']], $value);
        }

        // create
        return $this->createComponent($componentUID, $value);
    }

    /** @param array<string, mixed> $componentToDelete */
    public function deleteComponent(string $uid, array $componentToDelete): void
    {
        $this->deleteComponents($uid, $componentToDelete);
        $this->strapi->db()->query($uid)->delete(['where' => ['id' => $componentToDelete['id']]]);
    }

    /* Component relation handling for document operations */

    /**
     * Find the parent entry of a component instance by checking each candidate parent's `_cmps` join table.
     *
     * @param list<Schema> $parentSchemasForComponent
     * @return array{uid: string, table: string, parentId: mixed}|null
     */
    public function findComponentParent(Schema $componentSchema, int|string $componentId, array $parentSchemasForComponent): ?array
    {
        $db = $this->strapi->db();
        $identifiers = \Strapi\Database\Utils\Identifiers\Identifiers::global();
        $schemaManager = $db->getSchemaConnection();

        foreach ($parentSchemasForComponent as $parent) {
            if ($parent->collectionName === '') {
                continue;
            }

            $joinTableName = SchemaFactory::getComponentJoinTableName($parent->collectionName, $identifiers);

            try {
                if (!$schemaManager->tablesExist([$joinTableName])) {
                    continue;
                }

                $entityIdColumn = SchemaFactory::getComponentJoinColumnEntityName($identifiers);
                $componentIdColumn = SchemaFactory::getComponentJoinColumnInverseName($identifiers);
                $componentTypeColumn = SchemaFactory::getComponentTypeColumn($identifiers);

                $rows = $db->sql()->from($joinTableName)->select([$entityIdColumn])
                    ->where([$componentIdColumn => $componentId, $componentTypeColumn => $componentSchema->uid])
                    ->limit(1)->run();
                $parentRow = is_array($rows) ? ($rows[0] ?? null) : null;

                if ($parentRow !== null) {
                    return ['uid' => $parent->uid, 'table' => $parent->collectionName, 'parentId' => $parentRow[$entityIdColumn]];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * Finds content types and components that contain the given component.
     *
     * @return list<Schema>
     */
    public function getParentSchemasForComponent(Schema $componentSchema): array
    {
        $out = [];
        foreach ([...$this->strapi->contentTypes(), ...$this->strapi->components()] as $schema) {
            foreach ($schema->attributes as $attr) {
                $type = $attr['type'] ?? null;
                if (($type === 'component' && ($attr['component'] ?? null) === $componentSchema->uid)
                    || ($type === 'dynamiczone' && in_array($componentSchema->uid, $attr['components'] ?? [], true))) {
                    $out[] = $schema;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Determines if a component relation should be propagated to a new document version.
     *
     * @param array<string, mixed> $componentRelation
     * @param list<Schema> $parentSchemasForComponent
     */
    public function shouldPropagateComponentRelationToNewVersion(array $componentRelation, Schema $componentSchema, array $parentSchemasForComponent): bool
    {
        $identifiers = \Strapi\Database\Utils\Identifiers\Identifiers::global();
        $componentIdColumn = $identifiers->getJoinColumnAttributeIdName(\Strapi\Database\Utils\LodashWords::snakeCase($componentSchema->modelName));

        $componentId = $componentRelation[$componentIdColumn] ?? $componentRelation['parentId'] ?? null;
        if ($componentId === null) {
            return true;
        }

        $parent = $this->findComponentParent($componentSchema, $componentId, $parentSchemasForComponent);

        // Keep relation if component has no parent entry
        if ($parent === null) {
            return true;
        }

        $components = $this->strapi->components();
        if (isset($components[$parent['uid']])) {
            // If the parent is a component, we need to check its parents recursively
            $parentComponentSchema = $components[$parent['uid']];
            $grandParentSchemas = $this->getParentSchemasForComponent($parentComponentSchema);

            return $this->shouldPropagateComponentRelationToNewVersion($parent, $parentComponentSchema, $grandParentSchemas);
        }

        $parentContentType = $this->strapi->contentTypes()[$parent['uid']] ?? null;

        // Keep relation if parent doesn't have draft & publish enabled
        if ($parentContentType === null || !($parentContentType->options['draftAndPublish'] ?? false)) {
            return true;
        }

        // Discard relation if parent has draft & publish enabled
        return false;
    }

    /** @return \Closure(array<string, mixed>, Schema): bool */
    public function createComponentRelationFilter(): \Closure
    {
        return function (array $relation, Schema $model): bool {
            // Only apply component-specific filtering for components
            if ($model->modelType !== 'component') {
                return true;
            }

            $parentSchemas = $this->getParentSchemasForComponent($model);

            // Exit if no draft & publish parent types exist
            if ($parentSchemas === []) {
                return true;
            }

            return $this->shouldPropagateComponentRelationToNewVersion($relation, $model, $parentSchemas);
        };
    }
}
