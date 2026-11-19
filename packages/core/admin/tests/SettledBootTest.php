<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Core;
use Strapi\Core\Strapi;

/**
 * PHP port: a boot whose bootstrap is settled (`Strapi::bootstrapSettled()`: a bootstrap completed
 * for the same inputs on the same database; under PHP-FPM, most requests) skips the re-syncs that
 * completed bootstrap already ran: the super admin's permissions, the permissions cleanup and the
 * content-manager configurations. An unsettled boot runs them. Three boots on one SQLite file.
 */
final class SettledBootTest extends TestCase
{
    private const string CONFIGURATION_KEY = 'plugin_content_manager_configuration_content_types::api::article.article';

    private string $database = '';

    /** @var array{0: string|false, 1: mixed, 2: mixed} NODE_ENV before the test: getenv(), $_ENV, $_SERVER */
    private array $nodeEnv = [false, null, null];

    private static function appDir(): string
    {
        return dirname(__DIR__, 4) . '/examples/getstarted';
    }

    protected function setUp(): void
    {
        $this->database = sys_get_temp_dir() . '/strapi-settled-boot-' . bin2hex(random_bytes(6)) . '.db';
        $env = [
            'DATABASE_CLIENT' => 'sqlite',
            'DATABASE_FILENAME' => $this->database,
            'STRAPI_NO_EXIT' => '1',
            'LOG_LEVEL' => 'error',
            // set, so users-permissions does not generate one during the first bootstrap (which
            // changes the config, and so the inputs of the next boot)
            'JWT_SECRET' => 'settled-boot-test',
        ];
        foreach ($env as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }

        // development never settles (Strapi::bootstrapFingerprint()); an earlier boot in this
        // process set NODE_ENV in all three places, and $_SERVER is read first
        $this->nodeEnv = [getenv('NODE_ENV'), $_ENV['NODE_ENV'] ?? null, $_SERVER['NODE_ENV'] ?? null];
        putenv('NODE_ENV=production');
        $_ENV['NODE_ENV'] = $_SERVER['NODE_ENV'] = 'production';
    }

    protected function tearDown(): void
    {
        putenv('JWT_SECRET');
        unset($_ENV['JWT_SECRET']);
        [$getenv, $env, $server] = $this->nodeEnv;
        putenv(is_string($getenv) ? "NODE_ENV={$getenv}" : 'NODE_ENV');
        if ($env === null) {
            unset($_ENV['NODE_ENV']);
        } else {
            $_ENV['NODE_ENV'] = $env;
        }
        if ($server === null) {
            unset($_SERVER['NODE_ENV']);
        } else {
            $_SERVER['NODE_ENV'] = $server;
        }
        putenv('DATABASE_FILENAME=:memory:');
        $_ENV['DATABASE_FILENAME'] = ':memory:';
        @unlink($this->database);
        @unlink(self::appDir() . '/.tmp/bootstrap.settled');
    }

    private static function boot(): Strapi
    {
        return Core::createStrapi(['appDir' => self::appDir()])->load();
    }

    private static function hasPermission(Strapi $strapi, int $id): bool
    {
        return $strapi->db()->query('admin::permission')->count(['where' => ['id' => $id]]) === 1;
    }

    private static function hasConfiguration(Strapi $strapi): bool
    {
        return $strapi->db()->query('strapi::core-store')->count(['where' => ['key' => self::CONFIGURATION_KEY]]) === 1;
    }

    public function testASettledBootSkipsTheResyncsAndAnUnsettledOneRunsThem(): void
    {
        $strapi = self::boot();
        self::assertFalse($strapi->bootstrapSettled(), 'a new database');
        self::assertTrue(self::hasConfiguration($strapi));

        // what only a bootstrap re-sync repairs: an orphaned permission (no role, no API token),
        // a content type without its stored configuration
        $orphan = $strapi->db()->query('admin::permission')->create(['data' => ['action' => 'plugin::content-manager.explorer.read']]);
        $strapi->db()->query('strapi::core-store')->delete(['where' => ['key' => self::CONFIGURATION_KEY]]);
        $strapi->destroy();

        $strapi = self::boot();
        self::assertTrue($strapi->bootstrapSettled(), 'same inputs, same database');
        self::assertTrue(self::hasPermission($strapi, (int) $orphan['id']));
        self::assertFalse(self::hasConfiguration($strapi));
        $strapi->destroy();

        // a new deploy, a config change or another database: the record no longer matches
        file_put_contents(self::appDir() . '/.tmp/bootstrap.settled', 'other');

        $strapi = self::boot();
        self::assertFalse($strapi->bootstrapSettled());
        self::assertFalse(self::hasPermission($strapi, (int) $orphan['id']));
        self::assertTrue(self::hasConfiguration($strapi));
        $strapi->destroy();
    }

    public function testABootstrapThatDiesLeavesNoRecordForAReCreatedDatabase(): void
    {
        self::boot()->destroy();
        self::assertFileExists(self::appDir() . '/.tmp/bootstrap.settled');

        // the database is dropped; the next bootstrap dies after syncing the schema (a timeout, a
        // deploy), before the super admin's permissions and the configurations are restored
        unlink($this->database);
        $strapi = Core::createStrapi(['appDir' => self::appDir()]);
        $strapi->register();
        $strapi->hook('strapi::content-types.afterSync')->register(static function (): void {
            throw new \RuntimeException('the bootstrap dies');
        });
        try {
            $strapi->bootstrap();
            self::fail('the bootstrap should have died');
        } catch (\RuntimeException $error) {
            self::assertSame('the bootstrap dies', $error->getMessage());
        }
        $strapi->destroy();
        self::assertFileDoesNotExist(self::appDir() . '/.tmp/bootstrap.settled');

        // so the next boot runs the whole bootstrap, which restores them
        $strapi = self::boot();
        self::assertFalse($strapi->bootstrapSettled());
        self::assertTrue(self::hasConfiguration($strapi));
        $strapi->destroy();
    }
}
