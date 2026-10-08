<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Tests\Mocks;

/** Port of server/src/services/__mocks__/mock-strapi-data.ts (`components`, `plugins`, `apis`, `contentTypes`). */
final class MockStrapiData
{
    /** @return array<string, array<string, mixed>> */
    public static function components(): array
    {
        return [
            'basic.simple' => [
                'collectionName' => 'components_basic_simples',
                'info' => ['displayName' => 'simple', 'icon' => 'ambulance', 'description' => ''],
                'options' => [],
                'attributes' => ['name' => ['type' => 'string', 'required' => true], 'test' => ['type' => 'string']],
                'uid' => 'basic.simple',
                'category' => 'basic',
                'modelType' => 'component',
                'modelName' => 'simple',
                'globalId' => 'ComponentBasicSimple',
            ],
            'blog.test-como' => [
                'collectionName' => 'components_blog_test_comos',
                'info' => ['displayName' => 'test comp', 'icon' => 'air-freshener', 'description' => ''],
                'options' => [],
                'attributes' => ['name' => ['type' => 'string', 'default' => 'toto']],
                'uid' => 'blog.test-como',
                'category' => 'blog',
                'modelType' => 'component',
                'modelName' => 'test-como',
                'globalId' => 'ComponentBlogTestComo',
            ],
            'basic.relation' => [
                'collectionName' => 'components_basic_relations',
                'info' => ['displayName' => 'Relation'],
                'options' => [],
                'attributes' => [
                    'categories' => ['type' => 'relation', 'relation' => 'oneToMany', 'target' => 'api::category.category'],
                ],
                'uid' => 'basic.relation',
                'category' => 'basic',
                'modelType' => 'component',
                'modelName' => 'relation',
                'globalId' => 'ComponentBasicRelation',
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function plugins(): array
    {
        $contentTypes = MockContentTypes::contentTypes();

        return [
            'upload' => [
                'contentTypes' => [
                    'file' => $contentTypes['plugin::upload.file'],
                    'folder' => $contentTypes['plugin::upload.folder'],
                ],
                'routes' => [
                    'content-api' => [
                        'type' => 'content-api',
                        'routes' => [
                            [
                                'method' => 'POST',
                                'path' => '/',
                                'handler' => 'content-api.upload',
                                'config' => ['auth' => ['scope' => ['plugin::upload.content-api.upload']]],
                                'info' => ['pluginName' => 'upload', 'type' => 'content-api'],
                            ],
                            [
                                'method' => 'GET',
                                'path' => '/files',
                                'handler' => 'content-api.find',
                                'config' => ['auth' => ['scope' => ['plugin::upload.content-api.find']]],
                                'info' => ['pluginName' => 'upload', 'type' => 'content-api'],
                            ],
                            [
                                'method' => 'GET',
                                'path' => '/files/:id',
                                'handler' => 'content-api.findOne',
                                'config' => ['auth' => ['scope' => ['plugin::upload.content-api.findOne']]],
                                'info' => ['pluginName' => 'upload', 'type' => 'content-api'],
                            ],
                            [
                                'method' => 'DELETE',
                                'path' => '/files/:id',
                                'handler' => 'content-api.destroy',
                                'config' => ['auth' => ['scope' => ['plugin::upload.content-api.destroy']]],
                                'info' => ['pluginName' => 'upload', 'type' => 'content-api'],
                            ],
                        ],
                        'prefix' => '/upload',
                    ],
                ],
            ],
            'email' => [
                'contentTypes' => [],
            ],
            'users-permissions' => [
                'contentTypes' => [],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function apis(): array
    {
        $contentTypes = MockContentTypes::contentTypes();

        $route = static fn (string $method, string $path, string $action, string $api): array => [
            'method' => $method,
            'path' => $path,
            'handler' => "api::{$api}.{$api}.{$action}",
            'config' => ['auth' => ['scope' => ["api::{$api}.{$api}.{$action}"]]],
            'info' => ['apiName' => $api, 'type' => 'content-api'],
        ];

        return [
            'homepage' => [
                'contentTypes' => [
                    'homepage' => $contentTypes['api::homepage.homepage'],
                ],
                'routes' => [
                    'homepage' => [
                        'type' => 'content-api',
                        'routes' => [
                            $route('GET', '/homepage', 'find', 'homepage'),
                            $route('PUT', '/homepage', 'update', 'homepage'),
                            $route('DELETE', '/homepage', 'delete', 'homepage'),
                            $route('POST', '/homepage', 'create', 'homepage'),
                        ],
                    ],
                ],
            ],
            'kitchensink' => [
                'contentTypes' => [
                    'kitchensink' => $contentTypes['api::kitchensink.kitchensink'],
                ],
                'routes' => [
                    'kitchensink' => [
                        'routes' => [
                            $route('GET', '/kitchensinks', 'find', 'kitchensink'),
                            $route('GET', '/kitchensinks/:id', 'findOne', 'kitchensink'),
                            $route('POST', '/kitchensinks', 'create', 'kitchensink'),
                            $route('PUT', '/kitchensinks/:id', 'update', 'kitchensink'),
                            $route('DELETE', '/kitchensinks/:id', 'delete', 'kitchensink'),
                        ],
                        'type' => 'content-api',
                    ],
                ],
            ],
        ];
    }
}
