<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;

/**
 * Upstream unit tests assign a partial `global.strapi`. `Strapi\Core\Strapi` is final, so these tests
 * use an un-loaded instance of `examples/getstarted` whose registries (content types, components,
 * services) are filled with the fixtures each test needs.
 *
 * Loaded with `require_once` (the package's autoload-dev is not part of the root autoloader).
 */
final class StubStrapi
{
    public static function create(): Strapi
    {
        // same environment as Strapi\Core\Tests\BootedAppTestCase: never touch the example's database file
        putenv('DATABASE_CLIENT=sqlite');
        putenv('DATABASE_FILENAME=:memory:');
        putenv('STRAPI_NO_EXIT=1');
        putenv('LOG_LEVEL=error');
        $_ENV['DATABASE_CLIENT'] = 'sqlite';
        $_ENV['DATABASE_FILENAME'] = ':memory:';
        $_ENV['LOG_LEVEL'] = 'error';

        return new Strapi(['appDir' => dirname(__DIR__, 4) . '/examples/getstarted']);
    }

    /** @param array<string, mixed> $definition */
    public static function schema(string $uid, array $definition, string $modelType = 'contentType'): Schema
    {
        return new Schema(
            uid: $uid,
            modelType: $modelType,
            kind: $definition['kind'] ?? ($modelType === 'component' ? null : 'collectionType'),
            modelName: $uid,
            globalId: $uid,
            collectionName: $uid,
            plugin: null,
            apiName: null,
            category: null,
            info: $definition['info'] ?? [],
            options: $definition['options'] ?? [],
            pluginOptions: $definition['pluginOptions'] ?? [],
            attributes: $definition['attributes'] ?? [],
            config: $definition['config'] ?? [],
        );
    }

    /** @param array<string, array<string, mixed>> $contentTypes */
    public static function addContentTypes(Strapi $strapi, array $contentTypes): void
    {
        foreach ($contentTypes as $uid => $definition) {
            $strapi->get('content-types')->set($uid, self::schema($uid, $definition));
        }
    }

    /** @param array<string, array<string, mixed>> $components */
    public static function addComponents(Strapi $strapi, array $components): void
    {
        foreach ($components as $uid => $definition) {
            $strapi->get('components')->set($uid, self::schema($uid, $definition, 'component'));
        }
    }

    public static function setService(Strapi $strapi, string $uid, object $service): void
    {
        $strapi->get('services')->set($uid, $service);
    }
}
