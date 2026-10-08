<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests;

use Strapi\Core\Core;
use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;

/**
 * Upstream unit tests assign a partial `global.strapi`. `Strapi\Core\Strapi` is final, so these
 * tests use an un-loaded instance of a scratch app whose registries are filled with the fixtures
 * each test needs, installed as {@see Core::instance()} (upstream's `global.strapi`).
 *
 * Loaded with `require_once` (the package's autoload-dev is not part of the root autoloader).
 */
final class StubStrapi
{
    public static function create(?string $appDir = null): Strapi
    {
        putenv('DATABASE_CLIENT=sqlite');
        putenv('DATABASE_FILENAME=:memory:');
        putenv('STRAPI_NO_EXIT=1');
        putenv('LOG_LEVEL=error');
        $_ENV['DATABASE_CLIENT'] = 'sqlite';
        $_ENV['DATABASE_FILENAME'] = ':memory:';
        $_ENV['LOG_LEVEL'] = 'error';

        $strapi = new Strapi(['appDir' => $appDir ?? dirname(__DIR__, 4) . '/examples/getstarted']);
        Core::setInstance($strapi);

        return $strapi;
    }

    /** @param array<string, mixed> $definition */
    public static function schema(string $uid, array $definition, string $modelType = 'contentType'): Schema
    {
        $config = $definition['config'] ?? [];
        if (isset($definition['__schema__'])) {
            $config['__schema__'] = $definition['__schema__'];
        }

        return new Schema(
            uid: $uid,
            modelType: $modelType,
            kind: $definition['kind'] ?? ($modelType === 'component' ? null : 'collectionType'),
            modelName: $definition['modelName'] ?? $uid,
            globalId: $uid,
            collectionName: $definition['collectionName'] ?? $uid,
            plugin: $definition['plugin'] ?? null,
            apiName: $definition['apiName'] ?? null,
            category: $definition['category'] ?? null,
            info: $definition['info'] ?? [],
            options: $definition['options'] ?? [],
            pluginOptions: $definition['pluginOptions'] ?? [],
            attributes: $definition['attributes'] ?? [],
            config: $config,
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
}
