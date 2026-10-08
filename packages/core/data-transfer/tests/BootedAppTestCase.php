<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Core;
use Strapi\Core\Strapi;

/**
 * Upstream's data-transfer unit tests mock `strapi`; `Strapi\Core\Strapi` is final, so the tests
 * that need an instance boot tests/fixtures/app (articles with media, a relation, a component and
 * a dynamic zone; categories), copied to a temporary directory with its own SQLite database, once
 * per test class.
 */
abstract class BootedAppTestCase extends TestCase
{
    protected static ?Strapi $strapi = null;

    protected static string $appDir = '';

    public static function setUpBeforeClass(): void
    {
        self::$appDir = sys_get_temp_dir() . '/strapi-dts-app-' . bin2hex(random_bytes(6));
        self::copy(__DIR__ . '/fixtures/app', self::$appDir);
        mkdir(self::$appDir . '/public/uploads', 0o777, true);

        $env = [
            'DATABASE_CLIENT' => 'sqlite',
            'DATABASE_FILENAME' => self::$appDir . '/.tmp/data.db',
            'STRAPI_NO_EXIT' => '1',
            'STRAPI_TELEMETRY_DISABLED' => 'true',
            'LOG_LEVEL' => 'error',
        ];
        foreach ($env as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }

        self::$strapi = Core::createStrapi(['appDir' => self::$appDir])->load();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
        self::rmrf(self::$appDir);
        putenv('DATABASE_FILENAME');
        unset($_ENV['DATABASE_FILENAME']);
    }

    protected static function strapi(): Strapi
    {
        return self::$strapi ?? throw new \LogicException('Strapi is not booted');
    }

    private static function copy(string $from, string $to): void
    {
        if (is_dir($from)) {
            mkdir($to, 0o777, true);
            foreach (scandir($from) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::copy("{$from}/{$entry}", "{$to}/{$entry}");
                }
            }

            return;
        }
        copy($from, $to);
    }

    private static function rmrf(string $path): void
    {
        if ($path === '' || (!is_dir($path) && !is_file($path) && !is_link($path))) {
            return;
        }
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmrf("{$path}/{$entry}");
            }
        }
        rmdir($path);
    }
}
