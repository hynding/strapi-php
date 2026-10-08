<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Tests;

use Nyholm\Psr7\ServerRequest;
use Strapi\Core\Core;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;

/**
 * Upstream unit tests assign a partial `global.strapi`. `Strapi\Core\Strapi` is final, so these
 * tests use either an un-loaded `examples/getstarted` instance whose registries are filled with the
 * fixtures a test needs (`create()`), or a booted one on in-memory SQLite (`boot()`, with this
 * plugin installed) where upstream mocks the database.
 *
 * Loaded with `require_once` (the package's autoload-dev is not part of the root autoloader).
 */
final class I18nTestApp
{
    private static function env(): void
    {
        putenv('DATABASE_CLIENT=sqlite');
        putenv('DATABASE_FILENAME=:memory:');
        putenv('STRAPI_NO_EXIT=1');
        putenv('LOG_LEVEL=error');
        $_ENV['DATABASE_CLIENT'] = 'sqlite';
        $_ENV['DATABASE_FILENAME'] = ':memory:';
        $_ENV['LOG_LEVEL'] = 'error';
    }

    private static function appDir(): string
    {
        return dirname(__DIR__, 4) . '/examples/getstarted';
    }

    /** An un-loaded app with the i18n services registered. */
    public static function create(): Strapi
    {
        self::env();
        $strapi = new Strapi(['appDir' => self::appDir()]);
        foreach (require dirname(__DIR__) . '/server/src/services/index.php' as $name => $factory) {
            $strapi->get('services')->set("plugin::i18n.{$name}", $factory);
        }

        return $strapi;
    }

    public static function boot(): Strapi
    {
        self::env();

        return Core::createStrapi(['appDir' => self::appDir()])->load();
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
            collectionName: $definition['collectionName'] ?? str_replace(['::', '.'], '_', $uid),
            plugin: null,
            apiName: null,
            category: null,
            info: $definition['info'] ?? [],
            options: $definition['options'] ?? [],
            pluginOptions: $definition['pluginOptions'] ?? [],
            attributes: $definition['attributes'] ?? [],
        );
    }

    /** @param array<string, array<string, mixed>> $definitions */
    public static function addContentTypes(Strapi $strapi, array $definitions): void
    {
        foreach ($definitions as $uid => $definition) {
            $strapi->get('content-types')->set($uid, self::schema($uid, $definition));
        }
    }

    /** @param array<string, array<string, mixed>> $definitions */
    public static function addComponents(Strapi $strapi, array $definitions): void
    {
        foreach ($definitions as $uid => $definition) {
            $strapi->get('components')->set($uid, self::schema($uid, $definition, 'component'));
        }
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $query
     * @param array<string, string> $params
     * @param array<string, mixed> $state
     */
    public static function ctx(string $method = 'GET', string $uri = '/i18n', ?array $body = null, array $query = [], array $params = [], array $state = []): Context
    {
        $request = new ServerRequest($method, $uri);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $ctx = new Context($request, ['keys' => ['k1']]);
        $ctx->setQuery($query);
        $ctx->setParams($params);
        foreach ($state as $key => $value) {
            $ctx->state()->set($key, $value);
        }

        return $ctx;
    }

    /** @return array<string, mixed> */
    public static function createSuperAdmin(Strapi $strapi, string $email): array
    {
        $superAdmin = $strapi->service('admin::role')->getSuperAdmin();

        return $strapi->service('admin::user')->create([
            'firstname' => 'Kai',
            'lastname' => 'Doe',
            'email' => $email,
            'isActive' => true,
            'roles' => [$superAdmin['id'] ?? null],
        ]);
    }
}
