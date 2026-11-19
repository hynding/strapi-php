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
    }

    protected function tearDown(): void
    {
        putenv('JWT_SECRET');
        unset($_ENV['JWT_SECRET']);
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
}
