<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Tests\Services;

use PHPUnit\Framework\TestCase;
use Strapi\Plugin\Documentation\Services\Helpers\BuildComponentSchema;
use Strapi\Plugin\Documentation\Tests\Mocks\ContentTypeOverride;
use Strapi\Plugin\Documentation\Tests\Mocks\StrapiMock;

/** Port of server/src/services/__tests__/build-component-schema.test.ts. */
final class BuildComponentSchemaTest extends TestCase
{
    private const ID = ['oneOf' => [['type' => 'string'], ['type' => 'number']]];

    /**
     * The mocked strapi instance (`contentType()` always returns `{ info: {}, attributes: { test: { type: 'string' } } }`).
     *
     * @param list<array<string, mixed>> $pluginRoutes
     * @param list<array<string, mixed>> $apiRoutes
     */
    private static function strapi(array $pluginRoutes = [], array $apiRoutes = []): StrapiMock
    {
        return new StrapiMock(
            [],
            [],
            [
                'users-permissions' => [
                    'contentTypes' => ['role' => ['attributes' => ['test' => ['type' => 'string']]]],
                    'routes' => ['content-api' => ['routes' => $pluginRoutes]],
                ],
            ],
            [
                'restaurant' => [
                    'contentTypes' => ['restaurant' => ['attributes' => ['test' => ['type' => 'string']]]],
                    'routes' => ['restaurant' => ['routes' => $apiRoutes]],
                ],
            ],
        );
    }

    /**
     * @param list<array{name: string, getter: string, ctNames: list<string>}> $apiMocks
     *
     * @return array<string, mixed>
     */
    private static function build(StrapiMock $strapi, array $apiMocks): array
    {
        $schemas = [];
        foreach ($apiMocks as $mock) {
            $schemas = [...$schemas, ...BuildComponentSchema::buildComponentSchema(new ContentTypeOverride($strapi), $mock)];
        }

        return $schemas;
    }

    /** @return list<array{name: string, getter: string, ctNames: list<string>}> */
    private static function apiMocks(): array
    {
        return [
            [
                'name' => 'users-permissions',
                'getter' => 'plugin',
                'ctNames' => ['role'],
            ],
            ['name' => 'restaurant', 'getter' => 'api', 'ctNames' => ['restaurant']],
        ];
    }

    /** @return array<string, mixed> */
    private static function entity(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => self::ID,
                'documentId' => ['type' => 'string'],
                'test' => ['type' => 'string'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function response(string $typeName): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => ['$ref' => "#/components/schemas/{$typeName}"],
                'meta' => ['type' => 'object'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function listResponse(string $typeName): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => ['$ref' => "#/components/schemas/{$typeName}"],
                ],
                'meta' => [
                    'type' => 'object',
                    'properties' => [
                        'pagination' => [
                            'type' => 'object',
                            'properties' => [
                                'page' => ['type' => 'integer'],
                                'pageSize' => ['type' => 'integer', 'minimum' => 25],
                                'pageCount' => ['type' => 'integer', 'maximum' => 1],
                                'total' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function testBuildsTheResponseSchema(): void
    {
        $schemas = self::build(self::strapi(), self::apiMocks());

        $expectedSchemas = [
            'UsersPermissionsRole' => self::entity(),
            'UsersPermissionsRoleResponse' => self::response('UsersPermissionsRole'),
            'Restaurant' => self::entity(),
            'RestaurantResponse' => self::response('Restaurant'),
        ];

        self::assertEquals($expectedSchemas, $schemas);
    }

    public function testBuildsTheResponseListSchema(): void
    {
        $route = [['method' => 'GET', 'path' => '/test', 'handler' => 'test.find']];
        $schemas = self::build(self::strapi($route, $route), self::apiMocks());

        $expectedSchemas = [
            'UsersPermissionsRole' => self::entity(),
            'UsersPermissionsRoleListResponse' => self::listResponse('UsersPermissionsRole'),
            'UsersPermissionsRoleResponse' => self::response('UsersPermissionsRole'),
            'RestaurantListResponse' => self::listResponse('Restaurant'),
            'RestaurantResponse' => self::response('Restaurant'),
            'Restaurant' => self::entity(),
        ];

        self::assertEquals($expectedSchemas, $schemas);
    }

    public function testBuildsTheRequestSchema(): void
    {
        $route = [['method' => 'POST', 'path' => '/test', 'handler' => 'test.create']];
        $schemas = self::build(self::strapi($route, $route), self::apiMocks());

        // Just get the request objects
        $requestObjectsSchemas = array_filter($schemas, static fn (string $key): bool => str_ends_with($key, 'Request'), ARRAY_FILTER_USE_KEY);

        $request = [
            'type' => 'object',
            'required' => ['data'],
            'properties' => [
                'data' => [
                    'type' => 'object',
                    'properties' => [
                        'test' => ['type' => 'string'],
                    ],
                ],
            ],
        ];

        self::assertEquals([
            'UsersPermissionsRoleRequest' => $request,
            'RestaurantRequest' => $request,
        ], $requestObjectsSchemas);
    }

    public function testCreatesTheCorrectNameGivenMultipleContentTypes(): void
    {
        $apiMock = [
            'name' => 'users-permissions',
            'getter' => 'plugin',
            'ctNames' => ['permission', 'role', 'user'],
        ];

        $schemas = BuildComponentSchema::buildComponentSchema(new ContentTypeOverride(self::strapi()), $apiMock);

        self::assertSame([
            'UsersPermissionsPermission',
            'UsersPermissionsPermissionResponse',
            'UsersPermissionsRole',
            'UsersPermissionsRoleResponse',
            'UsersPermissionsUser',
            'UsersPermissionsUserResponse',
        ], array_keys($schemas));
    }
}

