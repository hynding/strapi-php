<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests;

require_once __DIR__ . '/RecordingLogger.php';

use Nyholm\Psr7\ServerRequest;
use Strapi\Core\Core;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/**
 * Upstream unit tests mock `global.strapi` piece by piece. `Strapi\Core\Strapi` is final, so the
 * tests of the auth/users side boot `examples/getstarted` (with this admin package) on an
 * in-memory SQLite database and run against the real services, replacing a service or the logger
 * where upstream asserts on a mock.
 *
 * Loaded with `require_once` (with RecordingLogger.php) (the package's autoload-dev is not part of the root autoloader).
 */
final class BootedAdminApp
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

    /** Replaces `strapi.log` with a logger that records `[level, message]` pairs. */
    public static function recordLogs(Strapi $strapi): RecordingLogger
    {
        $logger = new RecordingLogger();
        $strapi->set('logger', $logger);

        return $logger;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @param array<string, mixed> $query
     */
    public static function ctx(string $method = 'GET', string $uri = '/admin', ?array $body = null, array $headers = [], array $query = []): Context
    {
        $request = new ServerRequest($method, $uri, $headers);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        $ctx = new Context($request, ['keys' => ['k1', 'k2']]);
        if ($query !== []) {
            $ctx->setQuery($query);
        }

        return $ctx;
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    public static function createUser(Strapi $strapi, array $attributes): array
    {
        $superAdmin = $strapi->service('admin::role')->getSuperAdmin();

        return $strapi->service('admin::user')->create([
            'firstname' => 'Kai',
            'lastname' => 'Doe',
            'isActive' => true,
            'roles' => [$superAdmin['id']],
            ...$attributes,
        ]);
    }
}
