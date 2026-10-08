<?php

declare(strict_types=1);

namespace Strapi\Database\Utils;

use Strapi\Database\Utils\Identifiers\Identifiers;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Cuid2;

/**
 * Builds `Strapi\Types\Schema\Schema` objects from schema.json files the way core does
 * (packages/core/core/src/domain/content-type/index.ts, loaders/apis.ts, loaders/components.ts,
 * loaders/plugins/index.ts, plugins/i18n register.ts) and turns them into the database models
 * `Metadata::loadModels()` expects (packages/core/core/src/utils/transform-content-types-to-models.ts).
 *
 * @phpstan-import-type Model from \Strapi\Database\Metadata\Metadata
 */
final class SchemaFactory
{
    /**
     * Loads an API content type: `api::<apiName>.<singularName>`.
     *
     * @param array<string, mixed> $lifecycles
     */
    public static function fromJsonFile(string $path, ?string $uid = null, array $lifecycles = []): Schema
    {
        $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($json)) {
            throw new \InvalidArgumentException("Invalid schema file {$path}");
        }

        if ($uid === null) {
            if (isset($json['kind']) || isset($json['info']['singularName'])) {
                // api/<api>/content-types/<name>/schema.json
                $apiName = self::normalizeName(basename(dirname($path, 3)));
                $uid = 'api::' . $apiName . '.' . self::normalizeName($json['info']['singularName']);
            } else {
                // components/<category>/<name>.json
                $uid = basename(dirname($path)) . '.' . basename($path, '.json');
            }
        }

        return self::fromArray($json, $uid, $lifecycles);
    }

    /**
     * @param array<string, mixed> $json schema.json contents
     * @param array<string, mixed> $lifecycles
     */
    public static function fromArray(array $json, string $uid, array $lifecycles = []): Schema
    {
        if (preg_match('/^(api|plugin)::([^.]+)\.(.+)$/', $uid, $m) === 1 || preg_match('/^(admin|strapi)::(.+)$/', $uid, $m) === 1) {
            return self::contentType($json, $uid, $lifecycles);
        }

        return self::component($json, $uid);
    }

    /**
     * createContentType(): adds timestamps, publishedAt, creator fields, the i18n locale/localizations
     * attributes and the computed names.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $lifecycles
     */
    public static function contentType(array $schema, string $uid, array $lifecycles = []): Schema
    {
        $parsed = \Strapi\Types\Uid\Uid::parse($uid);
        $namespace = $parsed['namespace'];
        $info = $schema['info'] ?? [];
        $modelName = $info['singularName'] ?? $parsed['name'];
        $plugin = $namespace === 'plugin' ? $parsed['origin'] : ($namespace === 'admin' ? 'admin' : null);
        $apiName = $namespace === 'api' ? $parsed['origin'] : null;

        $collectionName = $schema['collectionName'] ?? null;
        if ($collectionName === null) {
            $collectionName = $plugin !== null && $namespace === 'plugin'
                ? strtolower("{$plugin}_{$modelName}")
                : $modelName;
        }

        $globalId = $schema['globalId'] ?? LodashWords::upperFirst(LodashWords::camelCase($plugin !== null && $namespace === 'plugin' ? "{$plugin}-{$modelName}" : $modelName));

        $options = $schema['options'] ?? [];
        if (!array_key_exists('draftAndPublish', $options)) {
            $options['draftAndPublish'] = false; // Disabled by default
        }

        $attributes = $schema['attributes'] ?? [];

        // addTimestamps
        $attributes[ContentTypes::CREATED_AT_ATTRIBUTE] = ['type' => 'datetime'];
        $attributes[ContentTypes::UPDATED_AT_ATTRIBUTE] = ['type' => 'datetime'];

        // addDraftAndPublish: publishedAt is added regardless of draft and publish being enabled
        $attributes[ContentTypes::PUBLISHED_AT_ATTRIBUTE] = [
            'type' => 'datetime',
            'configurable' => false,
            'writable' => true,
            'visible' => true,
            'default' => static fn (): \DateTimeImmutable => new \DateTimeImmutable(),
        ];

        // addCreatorFields
        $isPrivate = !($options['populateCreatorFields'] ?? false);
        foreach ([ContentTypes::CREATED_BY_ATTRIBUTE, ContentTypes::UPDATED_BY_ATTRIBUTE] as $creatorField) {
            $attributes[$creatorField] = [
                'type' => 'relation',
                'relation' => 'oneToOne',
                'target' => 'admin::user',
                'configurable' => false,
                'writable' => false,
                'visible' => false,
                'useJoinTable' => false,
                'private' => $isPrivate,
            ];
        }

        // i18n extendContentTypes(): locale + localizations on every content type
        $isLocalized = ($schema['pluginOptions']['i18n']['localized'] ?? false) === true;
        $attributes['locale'] = [
            'writable' => true,
            'private' => !$isLocalized,
            'configurable' => false,
            'visible' => false,
            'type' => 'string',
        ];
        $attributes['localizations'] = [
            'type' => 'relation',
            'relation' => 'oneToMany',
            'target' => $uid,
            'writable' => false,
            'private' => !$isLocalized,
            'configurable' => false,
            'visible' => false,
            'unstable_virtual' => true,
            'joinColumn' => [
                'name' => 'document_id',
                'referencedColumn' => 'document_id',
                'referencedTable' => Identifiers::global()->getTableName($collectionName),
                // ensure the population will not include the results we already loaded
                'on' => static fn (array $ctx): array => ['id' => ['$notIn' => array_map(static fn (array $r) => $r['id'], $ctx['results'] ?? [])]],
            ],
        ];

        $config = $schema['config'] ?? [];
        if ($lifecycles !== []) {
            $config['lifecycles'] = $lifecycles;
        }
        if (isset($schema['indexes'])) {
            $config['indexes'] = $schema['indexes'];
        }
        if (isset($schema['foreignKeys'])) {
            $config['foreignKeys'] = $schema['foreignKeys'];
        }

        return new Schema(
            uid: $uid,
            modelType: 'contentType',
            kind: $schema['kind'] ?? 'collectionType',
            modelName: $modelName,
            globalId: $globalId,
            collectionName: $collectionName,
            plugin: $plugin,
            apiName: $apiName,
            category: null,
            info: $info,
            options: $options,
            pluginOptions: $schema['pluginOptions'] ?? [],
            attributes: $attributes,
            config: $config,
        );
    }

    /** @param array<string, mixed> $schema */
    public static function component(array $schema, string $uid): Schema
    {
        if (empty($schema['collectionName'])) {
            throw new \InvalidArgumentException("Component {$uid} is missing a \"collectionName\" property.");
        }

        [$category, $key] = explode('.', $uid, 2);

        return new Schema(
            uid: $uid,
            modelType: 'component',
            kind: null,
            modelName: $key,
            globalId: $schema['globalId'] ?? LodashWords::upperFirst(LodashWords::camelCase("component_{$uid}")),
            collectionName: $schema['collectionName'],
            plugin: null,
            apiName: null,
            category: $category,
            info: $schema['info'] ?? [],
            options: $schema['options'] ?? [],
            pluginOptions: $schema['pluginOptions'] ?? [],
            attributes: $schema['attributes'] ?? [],
            config: $schema['config'] ?? [],
        );
    }

    /**
     * transformContentTypesToModels(): content types AND components to database models, including
     * the `<collectionName>_cmps` link models.
     *
     * @param iterable<Schema> $schemas
     *
     * @return list<Model>
     */
    public static function toModels(iterable $schemas, ?Identifiers $identifiers = null): array
    {
        $identifiers ??= Identifiers::global();
        $models = [];

        foreach ($schemas as $contentType) {
            if ($contentType->collectionName === '') {
                throw new \InvalidArgumentException('Content type "collectionName" is required');
            }
            if ($contentType->modelName === '') {
                throw new \InvalidArgumentException('Content type "modelName" is required');
            }

            $documentIdAttribute = $contentType->modelType === 'contentType'
                ? ['documentId' => ['type' => 'string', 'default' => static fn (): string => Cuid2::createId()]]
                : [];

            // Prevent user from creating a documentId attribute
            foreach (array_keys($contentType->attributes) as $attributeName) {
                $snake = LodashWords::snakeCase((string) $attributeName);
                if (in_array($snake, ['document_id', Identifiers::ID_COLUMN], true)) {
                    throw new \InvalidArgumentException(
                        "The attribute \"{$attributeName}\" is reserved and cannot be used in a model. Please rename \"{$contentType->modelName}\" attribute \"{$attributeName}\" to something else.",
                    );
                }
            }

            if (self::hasComponentsOrDz($contentType)) {
                $models[] = self::createCompoLinkModel($contentType, $identifiers);
            }

            $model = [
                'uid' => $contentType->uid,
                'singularName' => $contentType->modelName,
                'tableName' => $contentType->collectionName, // shortened in Metadata::loadModels()
                'attributes' => [
                    Identifiers::ID_COLUMN => ['type' => 'increments'],
                    ...$documentIdAttribute,
                    ...self::transformAttributes($contentType, $identifiers),
                ],
                'indexes' => $contentType->config['indexes'] ?? [],
                'foreignKeys' => $contentType->config['foreignKeys'] ?? [],
                'lifecycles' => $contentType->config['lifecycles'] ?? [],
            ];

            if ($contentType->modelType === 'contentType') {
                $columns = [];
                foreach (['documentId', 'locale', 'publishedAt'] as $n) {
                    if (isset($model['attributes'][$n])) {
                        $columns[] = $identifiers->getColumnName(LodashWords::snakeCase($n));
                    }
                }
                $model['indexes'][] = [
                    'name' => $identifiers->getIndexName([$contentType->collectionName, 'documents']),
                    'columns' => $columns,
                ];
            }

            $models[] = $model;
        }

        return $models;
    }

    /** @return array<string, array<string, mixed>> */
    public static function transformAttributes(Schema $contentType, Identifiers $identifiers): array
    {
        $out = [];
        foreach ($contentType->attributes as $name => $attribute) {
            $out[$name] = self::transformAttribute((string) $name, $attribute, $contentType, $identifiers);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $attribute
     *
     * @return array<string, mixed>
     */
    public static function transformAttribute(string $name, array $attribute, Schema $contentType, Identifiers $identifiers): array
    {
        switch ($attribute['type'] ?? null) {
            case 'media':
                return [
                    'type' => 'relation',
                    'relation' => ($attribute['multiple'] ?? false) === true ? 'morphMany' : 'morphOne',
                    'target' => 'plugin::upload.file',
                    'morphBy' => 'related',
                ];
            case 'component':
                $joinTableName = self::getComponentJoinTableName($contentType->collectionName, $identifiers);
                $joinColumnEntityName = self::getComponentJoinColumnEntityName($identifiers);
                $joinColumnInverseName = self::getComponentJoinColumnInverseName($identifiers);
                $compTypeColumn = self::getComponentTypeColumn($identifiers);

                return [
                    'type' => 'relation',
                    'relation' => ($attribute['repeatable'] ?? false) === true ? 'oneToMany' : 'oneToOne',
                    'target' => $attribute['component'],
                    'joinTable' => [
                        'name' => $joinTableName,
                        'joinColumn' => ['name' => $joinColumnEntityName, 'referencedColumn' => Identifiers::ID_COLUMN],
                        'inverseJoinColumn' => ['name' => $joinColumnInverseName, 'referencedColumn' => Identifiers::ID_COLUMN],
                        'on' => ['field' => $name],
                        'orderColumnName' => Identifiers::ORDER_COLUMN,
                        'orderBy' => ['order' => 'asc'],
                        'pivotColumns' => [$joinColumnEntityName, $joinColumnInverseName, Identifiers::FIELD_COLUMN, $compTypeColumn],
                    ],
                ];
            case 'dynamiczone':
                $joinTableName = self::getDzJoinTableName($contentType->collectionName, $identifiers);
                $joinColumnEntityName = self::getComponentJoinColumnEntityName($identifiers);
                $joinColumnInverseName = self::getComponentJoinColumnInverseName($identifiers);
                $compTypeColumn = self::getComponentTypeColumn($identifiers);

                return [
                    'type' => 'relation',
                    'relation' => 'morphToMany',
                    'joinTable' => [
                        'name' => $joinTableName,
                        'joinColumn' => ['name' => $joinColumnEntityName, 'referencedColumn' => Identifiers::ID_COLUMN],
                        'morphColumn' => [
                            'idColumn' => ['name' => $joinColumnInverseName, 'referencedColumn' => Identifiers::ID_COLUMN],
                            'typeColumn' => ['name' => $compTypeColumn],
                            'typeField' => '__component',
                        ],
                        'on' => ['field' => $name],
                        'orderBy' => ['order' => 'asc'],
                        'pivotColumns' => [$joinColumnEntityName, $joinColumnInverseName, Identifiers::FIELD_COLUMN, $compTypeColumn],
                    ],
                ];
            default:
                return $attribute;
        }
    }

    public static function hasComponentsOrDz(Schema $contentType): bool
    {
        foreach ($contentType->attributes as $attribute) {
            if (in_array($attribute['type'] ?? null, ['dynamiczone', 'component'], true)) {
                return true;
            }
        }

        return false;
    }

    public static function getComponentJoinTableName(string $collectionName, Identifiers $identifiers): string
    {
        return $identifiers->getNameFromTokens([
            ['name' => $collectionName, 'compressible' => true],
            ['name' => 'components', 'shortName' => 'cmps', 'compressible' => false],
        ]);
    }

    public static function getDzJoinTableName(string $collectionName, Identifiers $identifiers): string
    {
        return self::getComponentJoinTableName($collectionName, $identifiers);
    }

    public static function getComponentJoinColumnEntityName(Identifiers $identifiers): string
    {
        return $identifiers->getNameFromTokens([
            ['name' => 'entity', 'compressible' => false],
            ['name' => 'id', 'compressible' => false],
        ]);
    }

    public static function getComponentJoinColumnInverseName(Identifiers $identifiers): string
    {
        return $identifiers->getNameFromTokens([
            ['name' => 'component', 'shortName' => 'cmp', 'compressible' => false],
            ['name' => 'id', 'compressible' => false],
        ]);
    }

    public static function getComponentTypeColumn(Identifiers $identifiers): string
    {
        return $identifiers->getNameFromTokens([['name' => 'component_type', 'compressible' => false]]);
    }

    public static function getComponentFkIndexName(string $contentType, Identifiers $identifiers): string
    {
        return $identifiers->getNameFromTokens([
            ['name' => $contentType, 'compressible' => true],
            ['name' => 'entity', 'compressible' => false],
            ['name' => 'fk', 'compressible' => false],
        ]);
    }

    /** @return Model */
    private static function createCompoLinkModel(Schema $contentType, Identifiers $identifiers): array
    {
        $name = self::getComponentJoinTableName($contentType->collectionName, $identifiers);
        $entityId = self::getComponentJoinColumnEntityName($identifiers);
        $componentId = self::getComponentJoinColumnInverseName($identifiers);
        $compTypeColumn = self::getComponentTypeColumn($identifiers);
        $fkIndex = self::getComponentFkIndexName($contentType->collectionName, $identifiers);

        return [
            'singularName' => $name,
            'uid' => $name,
            'tableName' => $name,
            'attributes' => [
                Identifiers::ID_COLUMN => ['type' => 'increments'],
                $entityId => ['type' => 'integer', 'column' => ['unsigned' => true]],
                $componentId => ['type' => 'integer', 'column' => ['unsigned' => true]],
                $compTypeColumn => ['type' => 'string'],
                Identifiers::FIELD_COLUMN => ['type' => 'string'],
                Identifiers::ORDER_COLUMN => ['type' => 'float', 'column' => ['unsigned' => true, 'defaultTo' => null]],
            ],
            'indexes' => [
                ['name' => $identifiers->getIndexName([$contentType->collectionName, Identifiers::FIELD_COLUMN]), 'columns' => [Identifiers::FIELD_COLUMN]],
                ['name' => $identifiers->getIndexName([$contentType->collectionName, $compTypeColumn]), 'columns' => [$compTypeColumn]],
                ['name' => $fkIndex, 'columns' => [$entityId]],
                [
                    'name' => $identifiers->getUniqueIndexName([$contentType->collectionName]),
                    'columns' => [$entityId, $componentId, Identifiers::FIELD_COLUMN, $compTypeColumn],
                    'type' => 'unique',
                ],
            ],
            'foreignKeys' => [
                [
                    'name' => $fkIndex,
                    'columns' => [$entityId],
                    'referencedColumns' => [Identifiers::ID_COLUMN],
                    'referencedTable' => $identifiers->getTableName($contentType->collectionName),
                    'onDelete' => 'CASCADE',
                ],
            ],
        ];
    }

    /** loaders/apis.ts normalizeName: keep kebab-case names, kebab-case anything else. */
    private static function normalizeName(string $name): string
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) === 1 ? $name : LodashWords::kebabCase($name);
    }

    /**
     * The built-in models every Strapi database has (admin users, upload files, core store).
     *
     * @return array<string, Schema>
     */
    public static function builtinSchemas(): array
    {
        $adminUser = self::contentType([
            'collectionName' => 'admin_users',
            'info' => ['name' => 'User', 'singularName' => 'user', 'pluralName' => 'users', 'displayName' => 'User'],
            'options' => [],
            'attributes' => [
                'firstname' => ['type' => 'string'],
                'lastname' => ['type' => 'string'],
                'username' => ['type' => 'string'],
                'email' => ['type' => 'email', 'unique' => true, 'private' => true],
                'password' => ['type' => 'password', 'private' => true],
                'resetPasswordToken' => ['type' => 'string', 'private' => true],
                'registrationToken' => ['type' => 'string', 'private' => true],
                'isActive' => ['type' => 'boolean', 'default' => false, 'private' => true],
                'blocked' => ['type' => 'boolean', 'default' => false, 'private' => true],
                'preferedLanguage' => ['type' => 'string'],
            ],
        ], 'admin::user');

        $uploadFolder = self::contentType([
            'collectionName' => 'upload_folders',
            'info' => ['singularName' => 'folder', 'pluralName' => 'folders', 'displayName' => 'Folder'],
            'attributes' => [
                'name' => ['type' => 'string', 'required' => true],
                'pathId' => ['type' => 'integer', 'unique' => true, 'required' => true],
                'parent' => ['type' => 'relation', 'relation' => 'manyToOne', 'target' => 'plugin::upload.folder', 'inversedBy' => 'children'],
                'children' => ['type' => 'relation', 'relation' => 'oneToMany', 'target' => 'plugin::upload.folder', 'mappedBy' => 'parent'],
                'files' => ['type' => 'relation', 'relation' => 'oneToMany', 'target' => 'plugin::upload.file', 'mappedBy' => 'folder'],
                'path' => ['type' => 'string', 'required' => true],
            ],
            'indexes' => [
                ['name' => 'upload_folders_path_id_index', 'columns' => ['path_id'], 'type' => 'unique'],
                ['name' => 'upload_folders_path_index', 'columns' => ['path'], 'type' => 'unique'],
            ],
        ], 'plugin::upload.folder');

        $uploadFile = self::contentType([
            'collectionName' => 'files',
            'info' => ['singularName' => 'file', 'pluralName' => 'files', 'displayName' => 'File'],
            'attributes' => [
                'name' => ['type' => 'string', 'required' => true],
                'alternativeText' => ['type' => 'text'],
                'caption' => ['type' => 'text'],
                'focalPoint' => ['type' => 'json'],
                'width' => ['type' => 'integer'],
                'height' => ['type' => 'integer'],
                'formats' => ['type' => 'json'],
                'hash' => ['type' => 'string', 'required' => true],
                'ext' => ['type' => 'string'],
                'mime' => ['type' => 'string', 'required' => true],
                'size' => ['type' => 'decimal', 'required' => true],
                'url' => ['type' => 'text', 'required' => true],
                'previewUrl' => ['type' => 'text'],
                'provider' => ['type' => 'string', 'required' => true],
                'provider_metadata' => ['type' => 'json'],
                'related' => ['type' => 'relation', 'relation' => 'morphToMany'],
                'folder' => ['type' => 'relation', 'relation' => 'manyToOne', 'target' => 'plugin::upload.folder', 'inversedBy' => 'files', 'private' => true],
                'folderPath' => ['type' => 'string', 'required' => true, 'private' => true],
            ],
            'indexes' => [
                ['name' => 'upload_files_folder_path_index', 'columns' => ['folder_path'], 'type' => null],
                ['name' => 'upload_files_created_at_index', 'columns' => ['created_at'], 'type' => null],
                ['name' => 'upload_files_updated_at_index', 'columns' => ['updated_at'], 'type' => null],
                ['name' => 'upload_files_name_index', 'columns' => ['name'], 'type' => null],
                ['name' => 'upload_files_size_index', 'columns' => ['size'], 'type' => null],
                ['name' => 'upload_files_ext_index', 'columns' => ['ext'], 'type' => null],
            ],
        ], 'plugin::upload.file');

        $upUser = self::contentType([
            'collectionName' => 'up_users',
            'info' => ['name' => 'user', 'singularName' => 'user', 'pluralName' => 'users', 'displayName' => 'User'],
            'options' => ['timestamps' => true],
            'attributes' => [
                'username' => ['type' => 'string', 'unique' => true, 'required' => true],
                'email' => ['type' => 'email', 'required' => true],
                'provider' => ['type' => 'string'],
                'password' => ['type' => 'password', 'private' => true, 'searchable' => false],
                'resetPasswordToken' => ['type' => 'string', 'private' => true, 'searchable' => false],
                'confirmationToken' => ['type' => 'string', 'private' => true, 'searchable' => false],
                'confirmed' => ['type' => 'boolean', 'default' => false],
                'blocked' => ['type' => 'boolean', 'default' => false],
                'role' => ['type' => 'relation', 'relation' => 'manyToOne', 'target' => 'plugin::users-permissions.role', 'inversedBy' => 'users'],
            ],
        ], 'plugin::users-permissions.user');

        $upRole = self::contentType([
            'collectionName' => 'up_roles',
            'info' => ['name' => 'role', 'singularName' => 'role', 'pluralName' => 'roles', 'displayName' => 'Role'],
            'attributes' => [
                'name' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'string'],
                'type' => ['type' => 'string', 'unique' => true],
                'permissions' => ['type' => 'relation', 'relation' => 'oneToMany', 'target' => 'plugin::users-permissions.permission', 'mappedBy' => 'role'],
                'users' => ['type' => 'relation', 'relation' => 'oneToMany', 'target' => 'plugin::users-permissions.user', 'mappedBy' => 'role'],
            ],
        ], 'plugin::users-permissions.role');

        $upPermission = self::contentType([
            'collectionName' => 'up_permissions',
            'info' => ['name' => 'permission', 'singularName' => 'permission', 'pluralName' => 'permissions', 'displayName' => 'Permission'],
            'attributes' => [
                'action' => ['type' => 'string', 'required' => true],
                'role' => ['type' => 'relation', 'relation' => 'manyToOne', 'target' => 'plugin::users-permissions.role', 'inversedBy' => 'permissions'],
            ],
        ], 'plugin::users-permissions.permission');

        return [
            'admin::user' => $adminUser,
            'plugin::upload.folder' => $uploadFolder,
            'plugin::upload.file' => $uploadFile,
            'plugin::users-permissions.user' => $upUser,
            'plugin::users-permissions.role' => $upRole,
            'plugin::users-permissions.permission' => $upPermission,
        ];
    }

    /**
     * The core store model (packages/core/core/src/services/core-store.ts), a raw model, not a schema.
     *
     * @return Model
     */
    public static function coreStoreModel(): array
    {
        return [
            'uid' => 'strapi::core-store',
            'singularName' => 'strapi_core_store_settings',
            'tableName' => 'strapi_core_store_settings',
            'attributes' => [
                'id' => ['type' => 'increments'],
                'key' => ['type' => 'string'],
                'value' => ['type' => 'text'],
                'type' => ['type' => 'string'],
                'environment' => ['type' => 'string'],
                'tag' => ['type' => 'string'],
            ],
        ];
    }
}
