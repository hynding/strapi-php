<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Services;

use Strapi\ContentTypeBuilder\Services\SchemaBuilder\SchemaBuilder;
use Strapi\ContentTypeBuilder\Services\SchemaBuilder\SchemaHandler;
use Strapi\ContentTypeBuilder\Utils\Attributes;
use Strapi\ContentTypeBuilder\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Generators\Generators;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes as ContentTypesUtils;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/services/content-types.ts.
 *
 * `generateAPI()` calls `@strapi/generators`' `content-type` generator (strapi/generators), which
 * writes the PHP form of the API files (see examples/getstarted/src/api).
 */
final class ContentTypes
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public static function isCTBOwnedApplicationContentType(string $uid): bool
    {
        return str_starts_with($uid, 'api::');
    }

    public static function assertCTBOwnedApplicationContentType(string $uid): void
    {
        if (!self::isCTBOwnedApplicationContentType($uid)) {
            throw new ApplicationError("Content type \"{$uid}\" is not managed by CTB and cannot be deleted");
        }
    }

    /** @param list<string> $uids */
    private function pruneFolderReferences(array $uids): bool
    {
        return $this->contentStructureService()->commitFromUpdate(['deletedUids' => $uids]);
    }

    /** @param Schema|array<string, mixed> $model */
    public static function isContentTypeVisible(Schema|array $model): bool
    {
        $pluginOptions = $model instanceof Schema ? $model->pluginOptions : ($model['pluginOptions'] ?? []);
        $ctb = is_array($pluginOptions) ? ($pluginOptions['content-type-builder'] ?? null) : null;

        // getOr(true, 'pluginOptions.content-type-builder.visible', model) === true
        return !is_array($ctb) || !array_key_exists('visible', $ctb) || $ctb['visible'] === true;
    }

    /** @return list<string>|null */
    public static function getRestrictRelationsTo(Schema $contentType): ?array
    {
        $uid = $contentType->uid;
        if ($uid === Constants::CORE_UIDS['STRAPI_USER']) {
            // TODO: replace with an obj { relation: 'x', bidirectional: true|false }
            return ['oneWay', 'manyWay'];
        }

        if (
            str_starts_with($uid, Constants::CORE_UIDS['PREFIX'])
            || $uid === Constants::PLUGINS_UIDS['UPLOAD_FILE']
            || !self::isContentTypeVisible($contentType)
        ) {
            return [];
        }

        return null;
    }

    /**
     * Whether the registered model has `pluginOptions` (upstream leaves the key undefined when its
     * schema has none; the PHP model always carries an array).
     */
    public static function hasPluginOptions(Schema $model): bool
    {
        $raw = $model->config['__schema__'] ?? null;

        return $model->pluginOptions !== [] || (is_array($raw) && array_key_exists('pluginOptions', $raw));
    }

    /**
     * Format a contentType info to be used by the front-end.
     *
     * @return array<string, mixed>
     */
    public static function formatContentType(Schema $contentType): array
    {
        $info = $contentType->info;

        $schema = [
            ...ContentTypesUtils::getOptions($contentType),
            'displayName' => $info['displayName'] ?? null,
            'singularName' => $info['singularName'] ?? null,
            'pluralName' => $info['pluralName'] ?? null,
            'description' => array_key_exists('description', $info) ? $info['description'] : '',
        ];
        foreach (['displayName', 'singularName', 'pluralName'] as $key) {
            if (!array_key_exists($key, $info)) {
                unset($schema[$key]);
            }
        }
        if (self::hasPluginOptions($contentType)) {
            $schema['pluginOptions'] = $contentType->pluginOptions;
        } else {
            unset($schema['pluginOptions']);
        }
        $schema['kind'] = $contentType->kind ?? 'collectionType';
        $schema['collectionName'] = $contentType->collectionName;
        $schema['attributes'] = Attributes::formatAttributes($contentType);
        $schema['visible'] = self::isContentTypeVisible($contentType);
        $schema['restrictRelationsTo'] = self::getRestrictRelationsTo($contentType);

        $out = ['uid' => $contentType->uid];
        if ($contentType->plugin !== null) {
            $out['plugin'] = $contentType->plugin;
        }
        $out['apiID'] = $contentType->modelName;
        $out['schema'] = $schema;

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $contentTypes each `{ contentType, components? }`
     * @return list<SchemaHandler>
     */
    public function createContentTypes(array $contentTypes): array
    {
        $builder = SchemaBuilder::createBuilder($this->strapi);
        $createdContentTypes = [];
        $generatedApiNames = [];
        $schemaAlreadyRolledBack = false;

        try {
            foreach ($contentTypes as $contentType) {
                $createdContentTypes[] = $this->createContentType($contentType, [
                    'defaultBuilder' => $builder,
                    'generatedApiNames' => &$generatedApiNames,
                    'deferEvent' => true,
                ]);
            }

            $schemaFilesWritten = $builder->writeFiles();
            if (!$schemaFilesWritten) {
                $schemaAlreadyRolledBack = true;
                throw new ApplicationError('Invalid schema edition');
            }
        } catch (\Throwable $error) {
            SchemaMutation::rollbackSchemaMutation([
                'builder' => $builder,
                'apiHandler' => $this->apiHandler(),
                'generatedApiNames' => $generatedApiNames,
                'schemaAlreadyRolledBack' => $schemaAlreadyRolledBack,
            ]);

            throw $error;
        }

        foreach ($createdContentTypes as $contentType) {
            $this->strapi->eventHub()->emit('content-type.create', ['contentType' => $contentType]);
        }

        return $createdContentTypes;
    }

    /**
     * Creates a content type and handle the nested components sent with it.
     *
     * @param array<string, mixed> $input `{ contentType, components? }`
     * @param array{defaultBuilder?: SchemaBuilder, generatedApiNames?: list<string>, deferEvent?: bool} $options
     */
    public function createContentType(array $input, array $options = []): SchemaHandler
    {
        /** @var array<string, mixed> $contentType */
        $contentType = is_array($input['contentType'] ?? null) ? $input['contentType'] : [];
        /** @var list<array<string, mixed>> $components */
        $components = is_array($input['components'] ?? null) ? $input['components'] : [];

        $defaultBuilder = $options['defaultBuilder'] ?? null;
        $builder = $defaultBuilder ?? SchemaBuilder::createBuilder($this->strapi);
        $uidMap = $builder->createNewComponentUIDMap($components);

        $replaceTmpUIDs = Attributes::replaceTemporaryUIDs($uidMap, $this->strapi);

        $newContentType = $builder->createContentType($replaceTmpUIDs($contentType));

        // allow components to target the new contentType
        $targetContentType = static function (array $infos) use ($newContentType): array {
            foreach ($infos['attributes'] ?? [] as $key => $attribute) {
                if (is_array($attribute) && ($attribute['target'] ?? null) === '__contentType__') {
                    $infos['attributes'][$key]['target'] = $newContentType->uid();
                }
            }

            return $infos;
        };

        foreach ($components as $component) {
            $componentOptions = $replaceTmpUIDs($targetContentType($component));

            if (!array_key_exists('uid', $component)) {
                $builder->createComponent($componentOptions);
            } else {
                $builder->editComponent($componentOptions);
            }
        }

        $ownGeneratedApiNames = [];
        if (array_key_exists('generatedApiNames', $options)) {
            $generatedApiNames = &$options['generatedApiNames'];
        } else {
            $generatedApiNames = &$ownGeneratedApiNames;
        }
        $schemaAlreadyRolledBack = false;

        try {
            // Generate before writing the schema so a successful mutation retains the existing layout.
            if (!Attributes::truthy($contentType['plugin'] ?? null)) {
                $generatedApiNames[] = (string) ($contentType['singularName'] ?? '');

                $displayName = Attributes::truthy($contentType['displayName'] ?? null)
                    ? $contentType['displayName']
                    : ($contentType['info']['displayName'] ?? null);

                $this->generateAPI([
                    'displayName' => $displayName,
                    'singularName' => $contentType['singularName'] ?? null,
                    'pluralName' => $contentType['pluralName'] ?? null,
                    'kind' => $contentType['kind'] ?? null,
                ]);
            }

            if ($defaultBuilder === null) {
                $schemaFilesWritten = $builder->writeFiles();
                if (!$schemaFilesWritten) {
                    $schemaAlreadyRolledBack = true;
                    throw new ApplicationError('Invalid schema edition');
                }
            }
        } catch (\Throwable $error) {
            if ($defaultBuilder === null) {
                SchemaMutation::rollbackSchemaMutation([
                    'builder' => $builder,
                    'apiHandler' => $this->apiHandler(),
                    'generatedApiNames' => $generatedApiNames,
                    'schemaAlreadyRolledBack' => $schemaAlreadyRolledBack,
                ]);
            }

            throw $error;
        }

        if (!($options['deferEvent'] ?? false)) {
            $this->strapi->eventHub()->emit('content-type.create', ['contentType' => $newContentType]);
        }

        return $newContentType;
    }

    /**
     * Generate an API skeleton: `@strapi/generators`' `content-type` generator with
     * `destination: 'new'` and `bootstrapApi: true` (schema.json, then the core controller,
     * service and router), on the project root.
     *
     * @param array{singularName?: mixed, kind?: mixed, pluralName?: mixed, displayName?: mixed} $options
     */
    public function generateAPI(array $options): void
    {
        $singularName = $options['singularName'] ?? null;

        Generators::generate(
            'content-type',
            [
                'kind' => $options['kind'] ?? 'collectionType',
                'singularName' => $singularName,
                'id' => $singularName,
                'pluralName' => $options['pluralName'] ?? null,
                'displayName' => $options['displayName'] ?? null,
                'destination' => 'new',
                'bootstrapApi' => true,
                'attributes' => [],
            ],
            ['dir' => $this->strapi->dirs()->root],
        );
    }

    /**
     * Edits a contentType and handle the nested contentTypes sent with it.
     *
     * @param array<string, mixed> $input `{ contentType, components? }`
     */
    public function editContentType(string $uid, array $input): SchemaHandler
    {
        /** @var array<string, mixed> $contentType */
        $contentType = is_array($input['contentType'] ?? null) ? $input['contentType'] : [];
        /** @var list<array<string, mixed>> $components */
        $components = is_array($input['components'] ?? null) ? $input['components'] : [];

        $builder = SchemaBuilder::createBuilder($this->strapi);

        $handler = $builder->contentTypes[$uid] ?? throw new \TypeError("Cannot read properties of undefined (reading 'schema')");
        $previousSchema = $handler->schema();
        $isPluginContentType = Attributes::truthy($handler->plugin());
        $previousKind = $previousSchema['kind'] ?? null;
        $newKind = Attributes::truthy($contentType['kind'] ?? null) ? $contentType['kind'] : $previousKind;

        // Restore non-visible attributes from previous schema
        $previousAttributes = is_array($previousSchema['attributes'] ?? null) ? $previousSchema['attributes'] : [];
        $prevNonVisibleAttributes = [];
        foreach (ContentTypesUtils::getNonVisibleAttributes($previousSchema) as $key) {
            if (array_key_exists($key, $previousAttributes)) {
                $prevNonVisibleAttributes[$key] = $previousAttributes[$key];
            }
        }
        $contentType['attributes'] = self::lodashMerge($prevNonVisibleAttributes, is_array($contentType['attributes'] ?? null) ? $contentType['attributes'] : []);

        if ($newKind !== $previousKind && $newKind === 'singleType') {
            $entryCount = $this->strapi->db()->query($uid)->count();
            if ($entryCount > 1) {
                throw new ApplicationError('You cannot convert a collectionType to a singleType when having multiple entries in DB');
            }
        }

        $uidMap = $builder->createNewComponentUIDMap($components);
        $replaceTmpUIDs = Attributes::replaceTemporaryUIDs($uidMap, $this->strapi);

        $updatedContentType = $builder->editContentType(['uid' => $uid, ...$replaceTmpUIDs($contentType)]);

        foreach ($components as $component) {
            if (!array_key_exists('uid', $component)) {
                $builder->createComponent($replaceTmpUIDs($component));
            } else {
                $builder->editComponent($replaceTmpUIDs($component));
            }
        }

        if ($newKind !== $previousKind) {
            $schemaAlreadyRolledBack = false;
            $apiHandler = $this->apiHandler();
            $apiHandler->backup($uid);

            try {
                $apiHandler->clear($uid, ['preserveBackup' => true]);

                if (!$isPluginContentType) {
                    // generate new api skeleton
                    $updatedSchema = $updatedContentType->schema();
                    $this->generateAPI([
                        'displayName' => $updatedSchema['info']['displayName'] ?? null,
                        'singularName' => $updatedSchema['info']['singularName'] ?? null,
                        'pluralName' => $updatedSchema['info']['pluralName'] ?? null,
                        'kind' => $updatedSchema['kind'] ?? null,
                    ]);
                }

                $schemaFilesWritten = $builder->writeFiles();
                if (!$schemaFilesWritten) {
                    $schemaAlreadyRolledBack = true;
                    throw new ApplicationError('Invalid schema edition');
                }
                $this->pruneFolderReferences([$uid]);
            } catch (\Throwable $error) {
                SchemaMutation::rollbackSchemaMutation([
                    'builder' => $builder,
                    'apiHandler' => $apiHandler,
                    'backedUpApiUids' => [$uid],
                    'schemaAlreadyRolledBack' => $schemaAlreadyRolledBack,
                ]);

                throw $error;
            }

            foreach (SchemaMutation::finalizeSchemaMutation(['apiHandler' => $apiHandler, 'backedUpApiUids' => [$uid]]) as $error) {
                $this->strapi->log()->error($error->getMessage());
            }

            return $updatedContentType;
        }

        $builder->writeFiles();

        $this->strapi->eventHub()->emit('content-type.update', ['contentType' => $updatedContentType]);

        return $updatedContentType;
    }

    /** @param list<string> $uids */
    public function deleteContentTypes(array $uids): void
    {
        foreach ($uids as $uid) {
            self::assertCTBOwnedApplicationContentType($uid);
        }

        $builder = SchemaBuilder::createBuilder($this->strapi);
        $apiHandler = $this->apiHandler();

        $deletedContentTypes = [];
        $backedUpApiUids = [];
        $schemaAlreadyRolledBack = false;

        try {
            foreach ($uids as $uid) {
                $apiHandler->backup($uid);
                $backedUpApiUids[] = $uid;
                $deletedContentTypes[] = $builder->deleteContentType($uid);
            }

            $schemaFilesWritten = $builder->writeFiles();
            if (!$schemaFilesWritten) {
                $schemaAlreadyRolledBack = true;
                throw new ApplicationError('Invalid schema edition');
            }

            foreach ($uids as $uid) {
                $apiHandler->clear($uid, ['preserveBackup' => true]);
            }

            $this->pruneFolderReferences($uids);
        } catch (\Throwable $error) {
            SchemaMutation::rollbackSchemaMutation([
                'builder' => $builder,
                'apiHandler' => $apiHandler,
                'backedUpApiUids' => $backedUpApiUids,
                'schemaAlreadyRolledBack' => $schemaAlreadyRolledBack,
            ]);

            throw $error;
        }

        foreach (SchemaMutation::finalizeSchemaMutation(['apiHandler' => $apiHandler, 'backedUpApiUids' => $backedUpApiUids]) as $error) {
            $this->strapi->log()->error($error->getMessage());
        }

        foreach ($deletedContentTypes as $contentType) {
            $this->strapi->eventHub()->emit('content-type.delete', ['contentType' => $contentType]);
        }
    }

    /** Deletes a content type and the api files related to it. */
    public function deleteContentType(string $uid, ?SchemaBuilder $defaultBuilder = null): SchemaHandler
    {
        self::assertCTBOwnedApplicationContentType($uid);

        $builder = $defaultBuilder ?? SchemaBuilder::createBuilder($this->strapi);
        // make a backup
        $apiHandler = $this->apiHandler();
        $apiHandler->backup($uid);

        $schemaAlreadyRolledBack = false;

        if ($defaultBuilder === null) {
            try {
                $contentType = $builder->deleteContentType($uid);
                $schemaFilesWritten = $builder->writeFiles();
                if (!$schemaFilesWritten) {
                    $schemaAlreadyRolledBack = true;
                    throw new ApplicationError('Invalid schema edition');
                }
                $apiHandler->clear($uid, ['preserveBackup' => true]);
                $this->pruneFolderReferences([$uid]);
            } catch (\Throwable $error) {
                SchemaMutation::rollbackSchemaMutation([
                    'builder' => $builder,
                    'apiHandler' => $apiHandler,
                    'backedUpApiUids' => [$uid],
                    'schemaAlreadyRolledBack' => $schemaAlreadyRolledBack,
                ]);

                throw $error;
            }

            foreach (SchemaMutation::finalizeSchemaMutation(['apiHandler' => $apiHandler, 'backedUpApiUids' => [$uid]]) as $error) {
                $this->strapi->log()->error($error->getMessage());
            }
        } else {
            $contentType = $builder->deleteContentType($uid);
        }

        if ($defaultBuilder === null) {
            $this->strapi->eventHub()->emit('content-type.delete', ['contentType' => $contentType]);
        }

        return $contentType;
    }

    /** @return ApiHandler */
    private function apiHandler(): object
    {
        /** @var ApiHandler $service */
        $service = Utils::getService('api-handler', $this->strapi);

        return $service;
    }

    /** @return ContentStructure */
    private function contentStructureService(): object
    {
        /** @var ContentStructure $service */
        $service = Utils::getService('content-structure', $this->strapi);

        return $service;
    }

    /**
     * lodash `_.merge(object, source)`: deep merge of plain objects, arrays merged by index.
     *
     * @param array<array-key, mixed> $object
     * @param array<array-key, mixed> $source
     * @return array<array-key, mixed>
     */
    private static function lodashMerge(array $object, array $source): array
    {
        foreach ($source as $key => $value) {
            if (is_array($value) && is_array($object[$key] ?? null)) {
                $object[$key] = self::lodashMerge($object[$key], $value);
            } else {
                $object[$key] = $value;
            }
        }

        return $object;
    }
}
