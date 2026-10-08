<?php

declare(strict_types=1);

namespace Strapi\Plugin\ColorPicker\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Loaders\Plugins\GetEnabledPlugins;
use Strapi\Core\Strapi;
use Strapi\Plugin\ColorPicker\Register;

/**
 * Upstream has no server tests for the color-picker plugin. These check that `register`
 * (server/src/register.ts) adds `plugin::color-picker.color` once, with upstream's options, and that
 * the package is discovered as an installed plugin (the getstarted kitchensink uses the field).
 */
final class RegisterTest extends TestCase
{
    private static function appDir(): string
    {
        return dirname(__DIR__, 4) . '/examples/getstarted';
    }

    private static function createStrapi(): Strapi
    {
        $strapi = new Strapi(['appDir' => self::appDir()]);
        $strapi->get('plugins')->add('color-picker', require dirname(__DIR__) . '/strapi-server.php');

        return $strapi;
    }

    public function testTheModuleOnlyHasARegisterLifecycle(): void
    {
        $module = require dirname(__DIR__) . '/strapi-server.php';

        self::assertSame(['register'], array_keys($module));
    }

    public function testRegistersTheColorCustomField(): void
    {
        $strapi = self::createStrapi();
        $strapi->plugin('color-picker')->register();

        self::assertSame(
            ['plugin::color-picker.color' => ['name' => 'color', 'type' => 'string', 'plugin' => 'color-picker']],
            $strapi->get('custom-fields')->getAll(),
        );
    }

    public function testReRegisteringFails(): void
    {
        $strapi = self::createStrapi();
        (new Register())($strapi);

        $this->expectExceptionMessage("Custom field: 'plugin::color-picker.color' has already been registered");

        (new Register())($strapi);
    }

    public function testIsDiscoveredAsAnInstalledPlugin(): void
    {
        $strapi = new Strapi(['appDir' => self::appDir()]);
        $plugins = GetEnabledPlugins::getEnabledPlugins($strapi);

        self::assertArrayHasKey('color-picker', $plugins);
        self::assertSame('strapi/plugin-color-picker', $plugins['color-picker']['info']['packageName'] ?? null);
    }

    public function testTheGetstartedProjectNoLongerRegistersTheFieldItself(): void
    {
        $source = (string) file_get_contents(self::appDir() . '/src/index.php');

        self::assertStringNotContainsString("'color-picker'", $source);
    }
}
