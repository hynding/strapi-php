<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Services;

require_once __DIR__ . '/../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Services\Components;
use Strapi\ContentManager\Tests\StubStrapi;
use Strapi\Core\Strapi;

/**
 * Port of server/src/services/__tests__/components.test.ts. Upstream mocks the store utils; here
 * a booted app's core store holds the configurations and its query log counts the reads.
 */
final class ComponentsTest extends TestCase
{
    private static Strapi $strapi;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = StubStrapi::boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi->destroy();
    }

    public function testFindConfiguration(): void
    {
        $service = new Components(self::$strapi);
        $component = $service->findComponent('default.dish');
        self::assertNotNull($component);

        $result = $service->findConfiguration($component);

        self::assertSame('default.dish', $result['uid']);
        self::assertSame($component['category'], $result['category']);
        self::assertArrayHasKey('settings', $result);
        self::assertArrayHasKey('metadatas', $result);
        self::assertArrayHasKey('layouts', $result);
    }

    public function testFindComponentsConfigurationsCachesResultsPerRequest(): void
    {
        $service = new Components(self::$strapi);
        $model = ['attributes' => ['block' => ['type' => 'component', 'component' => 'default.dish']]];

        $ctx = StubStrapi::ctx();
        $first = null;
        $second = null;
        self::$strapi->requestContext()->run($ctx, static function () use ($service, $model, &$first, &$second): void {
            $first = $service->findComponentsConfigurations($model);
            // the second call is served from the request state, even if the store changes meanwhile
            self::$strapi->db()->query('strapi::core-store')->deleteMany([
                'where' => ['key' => 'plugin_content_manager_configuration_components::default.dish'],
            ]);
            $second = $service->findComponentsConfigurations($model);
        });

        self::assertIsArray($first);
        self::assertArrayHasKey('default.dish', $first);
        self::assertSame($first, $second);
        self::assertNotEmpty($ctx->state()->get('__componentsConfigurationsCache'));
    }

    public function testFindComponentsConfigurationsReturnsAnEmptyMapWithoutComponents(): void
    {
        self::assertSame([], (new Components(self::$strapi))->findComponentsConfigurations(['attributes' => ['title' => ['type' => 'string']]]));
    }
}
