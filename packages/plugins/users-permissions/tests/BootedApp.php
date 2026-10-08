<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Tests;

use Nyholm\Psr7\ServerRequest;
use Strapi\Core\Core;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/**
 * Upstream unit tests mock `global.strapi` piece by piece. `Strapi\Core\Strapi` is final, so these
 * tests boot `examples/getstarted` (which loads this plugin) on an in-memory SQLite database and
 * run against the real services, the real session manager and the real plugin store.
 *
 * Loaded with `require_once` (the package's autoload-dev is not part of the root autoloader).
 */
final class BootedApp
{
    public static function boot(): Strapi
    {
        putenv('DATABASE_CLIENT=sqlite');
        putenv('DATABASE_FILENAME=:memory:');
        putenv('STRAPI_NO_EXIT=1');
        putenv('LOG_LEVEL=error');
        $_ENV['DATABASE_CLIENT'] = 'sqlite';
        $_ENV['DATABASE_FILENAME'] = ':memory:';
        $_ENV['LOG_LEVEL'] = 'error';

        return Core::createStrapi(['appDir' => dirname(__DIR__, 4) . '/examples/getstarted'])->load();
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     * @param array<string, string> $params
     * @param array<string, mixed> $state
     */
    public static function ctx(
        string $method = 'GET',
        string $uri = 'http://localhost/api',
        ?array $body = null,
        array $headers = [],
        array $query = [],
        array $params = [],
        array $state = [],
    ): Context {
        $request = new ServerRequest($method, $uri, $headers);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $ctx = new Context($request, ['keys' => ['k1', 'k2']]);
        if ($query !== []) {
            $ctx->setQuery($query);
        }
        if ($params !== []) {
            $ctx->setParams($params);
        }
        foreach ($state as $key => $value) {
            $ctx->state()->set($key, $value);
        }

        return $ctx;
    }

    /**
     * A users-permissions user with the authenticated role.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public static function createUser(Strapi $strapi, array $attributes = []): array
    {
        $role = $strapi->db()->query('plugin::users-permissions.role')->findOne(['where' => ['type' => 'authenticated']]);

        return $strapi->service('plugin::users-permissions.user')->add([
            'username' => 'user' . bin2hex(random_bytes(4)),
            'email' => 'user' . bin2hex(random_bytes(4)) . '@strapi.io',
            'password' => 'Password123',
            'provider' => 'local',
            'confirmed' => true,
            'role' => $role['id'] ?? null,
            ...$attributes,
        ]);
    }

    /** `strapi.config.set(path, value)` returning the previous value, for restoring. */
    public static function setConfig(Strapi $strapi, string $path, mixed $value): mixed
    {
        $previous = $strapi->config()->get($path);
        $strapi->config()->set($path, $value);

        return $previous;
    }

    /**
     * Replaces `strapi.plugin('email').service('email')` with a recorder (upstream mocks it).
     *
     * @return \ArrayObject<int, array<string, mixed>> the sent emails
     */
    public static function recordEmails(Strapi $strapi, ?\Throwable $failWith = null): \ArrayObject
    {
        $sent = new \ArrayObject();
        $strapi->get('services')->set('plugin::email.email', new class ($sent, $failWith) {
            /** @param \ArrayObject<int, array<string, mixed>> $sent */
            public function __construct(private readonly \ArrayObject $sent, private readonly ?\Throwable $failWith)
            {
            }

            /** @param array<string, mixed> $options */
            public function send(array $options): void
            {
                $this->sent->append($options);
                if ($this->failWith !== null) {
                    throw $this->failWith;
                }
            }
        });

        return $sent;
    }
}
