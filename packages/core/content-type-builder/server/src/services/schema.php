<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Services;

use Strapi\ContentTypeBuilder\Services\SchemaBuilder\SchemaBuilder;
use Strapi\ContentTypeBuilder\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema as ModelSchema;
use Strapi\Utils\ContentTypes as ContentTypesUtils;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/services/schema.ts: the whole-schema read (`GET /schema`) and the batched
 * update the admin sends on save (`POST /update-schema`).
 *
 * @phpstan-type CTBSchema array{components: list<array<string, mixed>>, contentTypes: list<array<string, mixed>>, contentStructure?: mixed}
 */
final class Schema
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * @param CTBSchema $schema
     * @return CTBSchema
     */
    private static function removeEmptyDefaultsOnUpdates(array $schema): array
    {
        foreach (['components', 'contentTypes'] as $type) {
            foreach ($schema[$type] as $i => $model) {
                if (($model['action'] ?? null) === 'delete') {
                    continue;
                }

                foreach ($model['attributes'] ?? [] as $j => $attribute) {
                    if (($attribute['action'] ?? null) === 'update') {
                        $properties = $attribute['properties'] ?? [];

                        if (is_array($properties) && array_key_exists('default', $properties) && $properties['default'] === '') {
                            // properties.default = undefined
                            unset($schema[$type][$i]['attributes'][$j]['properties']['default']);
                        }
                    }
                }
            }
        }

        return $schema;
    }

    /**
     * @param CTBSchema $schema
     * @return CTBSchema
     */
    private static function removeDeletedUIDTargetFieldsOnUpdates(array $schema): array
    {
        foreach ($schema['contentTypes'] as $i => $contentType) {
            if (($contentType['action'] ?? null) === 'delete') {
                continue;
            }

            $attributes = $contentType['attributes'] ?? [];
            foreach ($attributes as $j => $attribute) {
                if (($attribute['action'] ?? null) !== 'update') {
                    continue;
                }

                $properties = $attribute['properties'] ?? [];
                $targetField = $properties['targetField'] ?? null;

                if (($properties['type'] ?? null) === 'uid' && is_string($targetField) && $targetField !== '') {
                    $found = false;
                    foreach ($attributes as $attr) {
                        if (($attr['name'] ?? null) === $targetField) {
                            $found = true;
                            break;
                        }
                    }

                    if (!$found) {
                        unset($schema['contentTypes'][$i]['attributes'][$j]['properties']['targetField']);
                    }
                }
            }
        }

        return $schema;
    }

    /**
     * @param ModelSchema $model
     * @return list<array<string, mixed>>
     */
    private static function formatAttributes(ModelSchema $model): array
    {
        $out = [];
        // only get attributes that can be seen in the CTB
        foreach (ContentTypesUtils::getVisibleAttributes($model) as $key) {
            $out[] = [...self::formatAttribute($model->attributes[$key]), 'name' => $key];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $attribute
     * @return array<string, mixed>
     */
    public static function formatAttribute(array $attribute): array
    {
        if (($attribute['type'] ?? null) === 'relation') {
            $inversedBy = $attribute['inversedBy'] ?? null;
            $mappedBy = $attribute['mappedBy'] ?? null;

            // conditions are kept as they are ("Explicitly preserve conditions if they exist")
            return [
                ...$attribute,
                'targetAttribute' => is_string($inversedBy) && $inversedBy !== '' ? $inversedBy : (is_string($mappedBy) && $mappedBy !== '' ? $mappedBy : null),
            ];
        }

        return $attribute;
    }

    /** @return array{contentStructure: mixed, contentTypes: array<string, array<string, mixed>>, components: array<string, array<string, mixed>>} */
    public function getSchema(): array
    {
        $contentTypes = [];
        foreach ($this->strapi->contentTypes() as $uid => $contentType) {
            $item = [
                'uid' => $contentType->uid,
                'modelName' => $contentType->modelName,
                'kind' => $contentType->kind,
                'globalId' => $contentType->globalId,
                'options' => $contentType->options,
            ];
            if (ContentTypes::hasPluginOptions($contentType)) {
                $item['pluginOptions'] = $contentType->pluginOptions;
            }
            if ($contentType->plugin !== null) {
                $item['plugin'] = $contentType->plugin;
            }
            $item['collectionName'] = $contentType->collectionName;
            $item['info'] = $contentType->info;
            $item['modelType'] = $contentType->modelType;
            $item['attributes'] = self::formatAttributes($contentType);
            $item['visible'] = ContentTypes::isContentTypeVisible($contentType);
            $item['restrictRelationsTo'] = ContentTypes::getRestrictRelationsTo($contentType);

            $contentTypes[(string) $uid] = $item;
        }

        $components = [];
        foreach ($this->strapi->components() as $uid => $component) {
            $components[(string) $uid] = [
                'uid' => $component->uid,
                'modelName' => $component->modelName,
                'globalId' => $component->globalId,
                'modelType' => $component->modelType,
                'collectionName' => $component->collectionName,
                'category' => $component->category,
                'info' => $component->info,
                'attributes' => self::formatAttributes($component),
            ];
        }

        $contentStructure = $this->strapi->get('content-structure')->getCleanedFile();

        return [
            'contentStructure' => $contentStructure,
            'contentTypes' => $contentTypes,
            'components' => $components,
        ];
    }

    /** @param CTBSchema $schema */
    public function updateSchema(array $schema): void
    {
        $builder = SchemaBuilder::createBuilder($this->strapi);
        /** @var ApiHandler $apiHandler */
        $apiHandler = Utils::getService('api-handler', $this->strapi);
        /** @var ContentTypes $contentTypesService */
        $contentTypesService = Utils::getService('content-types', $this->strapi);
        /** @var ContentStructure $contentStructureService */
        $contentStructureService = Utils::getService('content-structure', $this->strapi);

        $contentStructure = $schema['contentStructure'] ?? null;

        // Reject protected/plugin deletes before builders, API backups, or folder reconciliation can
        // mutate anything. This is the server-side invariant for crafted update requests.
        foreach ($schema['contentTypes'] as $contentType) {
            if (($contentType['action'] ?? null) === 'delete') {
                ContentTypes::assertCTBOwnedApplicationContentType((string) $contentType['uid']);
            }
        }

        // pre-process data
        $schema = self::removeEmptyDefaultsOnUpdates($schema);
        $schema = self::removeDeletedUIDTargetFieldsOnUpdates($schema);
        ['components' => $components, 'contentTypes' => $contentTypes] = $schema;

        $upsertedUids = [];
        $deletedUids = [];
        $generatedApiNames = [];
        $backedUpApiUids = [];
        $schemaAlreadyRolledBack = false;
        $APIsToDelete = [];
        foreach ($contentTypes as $contentType) {
            if (($contentType['action'] ?? null) === 'delete') {
                $APIsToDelete[] = (string) $contentType['uid'];
            }
        }

        $toAttributeMap = static function (array $attributes, bool $skipDeleted): array {
            $acc = [];
            foreach ($attributes as $attr) {
                // NOTE: handle renaming migrations here by comparing attr name & attr.properties.name
                if ($skipDeleted && ($attr['action'] ?? null) === 'delete') {
                    continue;
                }
                $acc[(string) $attr['name']] = $attr['properties'] ?? null;
            }

            return $acc;
        };

        try {
            foreach ($contentTypes as $contentType) {
                $action = $contentType['action'] ?? null;
                if ($action === 'create') {
                    $upsertedUids[(string) $contentType['uid']] = $contentType['kind'] ?? 'collectionType';
                } elseif ($action === 'update' && is_string($contentType['kind'] ?? null) && $contentType['kind'] !== '') {
                    // A kind switch in the same save must override the registry's stale kind,
                    // otherwise a folder assignment into the new section fails validation.
                    $upsertedUids[(string) $contentType['uid']] = $contentType['kind'];
                } elseif ($action === 'delete') {
                    $deletedUids[] = (string) $contentType['uid'];
                }
            }

            // Validate folder references before anything is written. This will throw if invalid.
            $contentStructureService->validateFromUpdate([
                'incomingStructure' => $contentStructure,
                'upsertedUids' => $upsertedUids,
                'deletedUids' => $deletedUids,
            ]);

            // we pre create empty types
            foreach ($contentTypes as $contentType) {
                if (($contentType['action'] ?? null) === 'create') {
                    $builder->createContentType([...$contentType, 'attributes' => []]);
                }
            }

            // we pre create empty types
            foreach ($components as $component) {
                if (($component['action'] ?? null) === 'create') {
                    $builder->createComponent([...$component, 'attributes' => []]);
                }
            }

            foreach ($contentTypes as $contentType) {
                $action = $contentType['action'] ?? null;
                $uid = (string) ($contentType['uid'] ?? '');

                if ($action === 'create') {
                    $builder->createContentTypeAttributes($uid, $toAttributeMap($contentType['attributes'] ?? [], false));

                    if (!is_string($contentType['plugin'] ?? null) || $contentType['plugin'] === '') {
                        // Track before generation because a generator failure can leave a partial skeleton.
                        $generatedApiNames[] = (string) $contentType['singularName'];
                        $contentTypesService->generateAPI([
                            'displayName' => $contentType['displayName'] ?? null,
                            'singularName' => $contentType['singularName'] ?? null,
                            'pluralName' => $contentType['pluralName'] ?? null,
                            'kind' => $contentType['kind'] ?? null,
                        ]);
                    }
                }

                if ($action === 'update') {
                    $builder->editContentType([...$contentType, 'attributes' => $toAttributeMap($contentType['attributes'] ?? [], true)]);
                }

                if ($action === 'delete') {
                    $builder->deleteContentType($uid);
                    $apiHandler->backup($uid);
                    $backedUpApiUids[] = $uid;
                }
            }

            foreach ($components as $component) {
                $action = $component['action'] ?? null;
                $uid = (string) ($component['uid'] ?? '');

                if ($action === 'create') {
                    $builder->createComponentAttributes($uid, $toAttributeMap($component['attributes'] ?? [], false));
                }

                if ($action === 'update') {
                    $builder->editComponent([...$component, 'attributes' => $toAttributeMap($component['attributes'] ?? [], true)]);
                }

                if ($action === 'delete') {
                    $builder->deleteComponent($uid);
                }
            }

            // run sanity checks on the schema
            // Relations target existing types
            // Bidirectional relation have their counterpart in the schema
            // Components target existing components
            // Nested components target existing components
            // Dynamic zones target existing components

            $schemaFilesWritten = $builder->writeFiles();

            if (!$schemaFilesWritten) {
                $schemaAlreadyRolledBack = true;
                throw new ApplicationError('Invalid schema edition');
            }

            foreach ($APIsToDelete as $uid) {
                $apiHandler->clear($uid, ['preserveBackup' => true]);
            }

            // Commit the single folder file last. Every preceding filesystem mutation can be restored.
            $contentStructureService->commitFromUpdate([
                'incomingStructure' => $contentStructure,
                'deletedUids' => $deletedUids,
            ]);
        } catch (\Throwable $error) {
            SchemaMutation::rollbackSchemaMutation([
                'builder' => $builder,
                'apiHandler' => $apiHandler,
                'backedUpApiUids' => $backedUpApiUids,
                'generatedApiNames' => $generatedApiNames,
                'schemaAlreadyRolledBack' => $schemaAlreadyRolledBack,
            ]);

            throw $error;
        }

        // Backup cleanup is deliberately outside the compensating boundary: at this point all
        // user-visible artifacts, including groups.json, have committed.
        foreach (SchemaMutation::finalizeSchemaMutation(['apiHandler' => $apiHandler, 'backedUpApiUids' => $backedUpApiUids]) as $error) {
            $this->strapi->log()->error($error->getMessage());
        }

        $eventHub = $this->strapi->eventHub();
        foreach ($contentTypes as $contentType) {
            $action = $contentType['action'] ?? null;
            if (in_array($action, ['delete', 'update', 'create'], true)) {
                $eventHub->emit("content-type.{$action}", ['contentType' => $builder->contentTypes[(string) $contentType['uid']] ?? null]);
            }
        }

        foreach ($components as $component) {
            $action = $component['action'] ?? null;
            if (in_array($action, ['delete', 'update', 'create'], true)) {
                $eventHub->emit("component.{$action}", ['component' => $builder->components[(string) $component['uid']] ?? null]);
            }
        }
    }
}
