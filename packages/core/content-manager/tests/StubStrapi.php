<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests;

use Nyholm\Psr7\ServerRequest;
use Strapi\ContentManager\Services\Utils\Populate;
use Strapi\Core\Core;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;

/**
 * Upstream unit tests assign a partial `global.strapi` and mock `getService`. `Strapi\Core\Strapi`
 * is final, so these tests use an un-loaded instance of `examples/getstarted` whose registries
 * (content types, components, services) are filled with the fixtures and stubs each test needs,
 * or a booted one (`boot()`, in-memory SQLite) where upstream mocks the database.
 *
 * Loaded with `require_once` (the package's autoload-dev is not part of the root autoloader).
 */
final class StubStrapi
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

    public static function create(): Strapi
    {
        self::env();
        Populate::clearCaches();

        $strapi = new Strapi(['appDir' => dirname(__DIR__, 4) . '/examples/getstarted']);
        // the content-manager services the code under test resolves through getService()
        foreach (require dirname(__DIR__) . '/server/src/services/index.php' as $name => $factory) {
            $strapi->get('services')->set("plugin::content-manager.{$name}", $factory);
        }

        return $strapi;
    }

    public static function boot(): Strapi
    {
        self::env();
        Populate::clearCaches();

        return Core::createStrapi(['appDir' => dirname(__DIR__, 4) . '/examples/getstarted'])->load();
    }

    /**
     * Creates an active super admin.
     *
     * @return array<string, mixed>
     */
    public static function createSuperAdmin(Strapi $strapi, string $email): array
    {
        /** @var \Strapi\Admin\Services\Role $roles */
        $roles = $strapi->service('admin::role');
        /** @var \Strapi\Admin\Services\User $users */
        $users = $strapi->service('admin::user');
        $superAdmin = $roles->getSuperAdmin();

        return $users->create([
            'firstname' => 'Kai',
            'lastname' => 'Doe',
            'email' => $email,
            'isActive' => true,
            'roles' => [$superAdmin['id'] ?? null],
        ]);
    }

    /** @param array<string, mixed> $user */
    public static function userAbility(Strapi $strapi, array $user): \Strapi\Permissions\Engine\Abilities\Ability
    {
        /** @var \Strapi\Admin\Services\Permission $permission */
        $permission = $strapi->service('admin::permission');

        return $permission->engine->generateUserAbility($user);
    }

    /** @param array<string, mixed> $definition */
    public static function schema(string $uid, array $definition, string $modelType = 'contentType'): Schema
    {
        return new Schema(
            uid: $uid,
            modelType: $modelType,
            kind: $definition['kind'] ?? ($modelType === 'component' ? null : 'collectionType'),
            modelName: $definition['modelName'] ?? $uid,
            globalId: $uid,
            collectionName: $definition['collectionName'] ?? $uid,
            plugin: null,
            apiName: null,
            category: $definition['category'] ?? null,
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

    public static function setService(Strapi $strapi, string $name, object $service): void
    {
        $uid = str_contains($name, '::') ? $name : "plugin::content-manager.{$name}";
        // the registry calls a callable value as a factory: wrap the (possibly invokable) stub
        $strapi->get('services')->set($uid, static fn (): object => $service);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $query
     * @param array<string, string> $params
     * @param array<string, mixed> $state
     */
    public static function ctx(string $method = 'GET', ?array $body = null, array $query = [], array $params = [], array $state = []): Context
    {
        $request = new ServerRequest($method, '/content-manager');
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
}
