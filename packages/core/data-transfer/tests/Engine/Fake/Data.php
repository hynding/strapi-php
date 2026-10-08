<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Engine\Fake;

/** The fixtures of engine.test.ts: schemas, metadata and each stage's default stream data. */
final class Data
{
    public const array METADATA = ['createdAt' => '2022-11-23T09:26:43.463Z', 'strapi' => ['version' => '1.2.3']];

    public const array LINKS = [
        ['kind' => 'relation.basic', 'relation' => 'oneToOne', 'left' => ['type' => 'api::foo.foo', 'ref' => 1, 'field' => 'foo'], 'right' => ['type' => 'api::bar.bar', 'ref' => 2, 'field' => 'bar']],
        ['kind' => 'relation.basic', 'relation' => 'oneToMany', 'left' => ['type' => 'api::foo.foo', 'ref' => 1, 'field' => 'foos'], 'right' => ['type' => 'api::bar.bar', 'ref' => 2, 'field' => 'bar']],
        ['kind' => 'relation.basic', 'relation' => 'oneToMany', 'left' => ['type' => 'basic.foo', 'field' => 'foo', 'ref' => 1], 'right' => ['type' => 'api::foo.foo', 'ref' => 1]],
    ];

    public const array ENTITIES = [
        ['id' => 1, 'type' => 'api::foo.foo', 'data' => ['foo' => 'bar']],
        ['id' => 2, 'type' => 'api::bar.bar', 'data' => ['bar' => 'foo']],
        ['id' => 1, 'type' => 'admin::permission', 'data' => ['foo' => 'bar']],
        ['id' => 2, 'type' => 'api::homepage.homepage', 'data' => ['bar' => 'foo']],
    ];

    public const array CONFIGURATION = [['key' => 'foo', 'value' => 'alice'], ['key' => 'bar', 'value' => 'bob']];

    /** @return array<string, array<string, mixed>> */
    public static function schemas(): array
    {
        $createdBy = ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'admin::user', 'configurable' => false, 'writable' => false, 'visible' => false, 'useJoinTable' => false, 'private' => true];

        return [
            'admin::permission' => [
                'collectionName' => 'admin_permissions',
                'info' => ['name' => 'Permission', 'description' => '', 'singularName' => 'permission', 'pluralName' => 'permissions', 'displayName' => 'Permission'],
                'options' => [],
                'pluginOptions' => ['content-manager' => ['visible' => false], 'content-type-builder' => ['visible' => false]],
                'attributes' => [
                    'action' => ['type' => 'string', 'minLength' => 1, 'configurable' => false, 'required' => true],
                    'subject' => ['type' => 'string', 'minLength' => 1, 'configurable' => false, 'required' => false],
                    'properties' => ['type' => 'json', 'configurable' => false, 'required' => false, 'default' => []],
                    'conditions' => ['type' => 'json', 'configurable' => false, 'required' => false, 'default' => []],
                    'role' => ['configurable' => false, 'type' => 'relation', 'relation' => 'manyToOne', 'inversedBy' => 'permissions', 'target' => 'admin::role'],
                    'createdAt' => ['type' => 'datetime'],
                    'updatedAt' => ['type' => 'datetime'],
                    'createdBy' => $createdBy,
                    'updatedBy' => $createdBy,
                ],
                'kind' => 'collectionType',
                'modelType' => 'contentType',
                'modelName' => 'permission',
                'uid' => 'admin::permission',
                'plugin' => 'admin',
                'globalId' => 'AdminPermission',
            ],
            'api::homepage.homepage' => [
                'collectionName' => 'homepages',
                'info' => ['displayName' => 'Homepage', 'singularName' => 'homepage', 'pluralName' => 'homepages'],
                'options' => [],
                'pluginOptions' => ['i18n' => ['localized' => true]],
                'attributes' => [
                    'title' => ['type' => 'string', 'required' => true, 'pluginOptions' => ['i18n' => ['localized' => true]]],
                    'slug' => ['type' => 'uid', 'targetField' => 'title', 'required' => true, 'pluginOptions' => ['i18n' => ['localized' => true]]],
                    'single' => ['type' => 'media', 'allowedTypes' => ['images', 'files', 'videos'], 'required' => false],
                    'multiple' => ['type' => 'media', 'multiple' => true, 'allowedTypes' => ['images', 'videos'], 'required' => false],
                    'createdAt' => ['type' => 'datetime'],
                    'updatedAt' => ['type' => 'datetime'],
                    'publishedAt' => ['type' => 'datetime', 'configurable' => false, 'writable' => true, 'visible' => false],
                    'createdBy' => $createdBy,
                    'updatedBy' => $createdBy,
                    'localizations' => ['writable' => true, 'private' => false, 'configurable' => false, 'visible' => false, 'type' => 'relation', 'relation' => 'oneToMany', 'target' => 'api::homepage.homepage'],
                    'locale' => ['writable' => true, 'private' => false, 'configurable' => false, 'visible' => false, 'type' => 'string'],
                ],
                'kind' => 'singleType',
                'modelType' => 'contentType',
                'modelName' => 'homepage',
                'uid' => 'api::homepage.homepage',
                'globalId' => 'Homepage',
            ],
            'api::bar.bar' => [
                'kind' => 'collectionType',
                'collectionName' => 'bars',
                'modelType' => 'contentType',
                'info' => ['singularName' => 'bar', 'pluralName' => 'bars', 'displayName' => 'bar', 'description' => ''],
                'options' => [],
                'pluginOptions' => [],
                'attributes' => [
                    'bar' => ['type' => 'integer'],
                    'foo' => ['displayName' => 'foo', 'type' => 'component', 'repeatable' => false, 'component' => 'basic.foo'],
                ],
            ],
            'api::foo.foo' => [
                'kind' => 'collectionType',
                'collectionName' => 'foos',
                'modelType' => 'contentType',
                'info' => ['singularName' => 'foo', 'pluralName' => 'foos', 'displayName' => 'foo'],
                'options' => [],
                'pluginOptions' => [],
                'attributes' => ['foo' => ['type' => 'string']],
            ],
            'basic.foo' => [
                'collectionName' => 'components_basic_foos',
                'info' => ['displayName' => 'Good Basic'],
                'options' => [],
                'attributes' => ['foo' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'api::foo.foo']],
                'modelType' => 'component',
                'modelName' => 'foo-basic',
                'uid' => 'basic.foo',
                'globalId' => 'ComponentBasicFoo',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function schemaStream(): array
    {
        $schema = static fn (string $uid, string $name, array $attributes): array => [
            'uid' => $uid, 'kind' => 'collectionType', 'modelName' => $name, 'globalId' => $name,
            'info' => ['displayName' => $name, 'singularName' => $name, 'pluralName' => "{$name}s"],
            'modelType' => 'contentType', 'attributes' => $attributes,
        ];

        return [
            $schema('api::foo.foo', 'foo', ['foo' => ['type' => 'string']]),
            $schema('api::bar.bar', 'bar', ['bar' => ['type' => 'integer']]),
            $schema('api::homepage.homepage', 'homepage', ['action' => ['type' => 'string']]),
            $schema('api::permission.permission', 'permission', ['action' => ['type' => 'string']]),
        ];
    }

    /** @return list<array<string, mixed>> two assets streamed in 3 and 6 chunks */
    public static function assets(): array
    {
        return [
            ['filename' => 'foo.jpg', 'filepath' => '/tmp/tests/foo.jpg', 'stats' => ['size' => 24], 'stream' => (static fn () => yield from ['1', '2', '3'])()],
            ['filename' => 'bar.jpg', 'filepath' => 'C:\\tests\\bar.jpg', 'stats' => ['size' => 48], 'stream' => (static fn () => yield from ['4', '5', '6', '7', '8', '9'])()],
        ];
    }
}
