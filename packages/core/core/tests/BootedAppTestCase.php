<?php

declare(strict_types=1);

namespace Strapi\Core\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Core;
use Strapi\Core\Strapi;

/**
 * Upstream unit tests mock `global.strapi`; `Strapi\Core\Strapi` is final, so the tests that need
 * an instance boot `examples/getstarted` once per class on an in-memory SQLite database.
 */
abstract class BootedAppTestCase extends TestCase
{
    protected static ?Strapi $strapi = null;

    public static function setUpBeforeClass(): void
    {
        putenv('DATABASE_CLIENT=sqlite');
        putenv('DATABASE_FILENAME=:memory:');
        putenv('STRAPI_NO_EXIT=1');
        putenv('LOG_LEVEL=error');
        $_ENV['DATABASE_CLIENT'] = 'sqlite';
        $_ENV['DATABASE_FILENAME'] = ':memory:';
        $_ENV['LOG_LEVEL'] = 'error';

        self::$strapi = Core::createStrapi(['appDir' => dirname(__DIR__, 4) . '/examples/getstarted'])->load();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    protected static function strapi(): Strapi
    {
        return self::$strapi ?? throw new \LogicException('Strapi is not booted');
    }
}
