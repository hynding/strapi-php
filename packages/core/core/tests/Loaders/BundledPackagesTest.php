<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Loaders;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Loaders\Plugins\GetEnabledPlugins;

/** strapi-php is installed as one package (`hynding/strapi-php`) that replaces every `strapi/*` package. */
final class BundledPackagesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/strapi-bundle-' . bin2hex(random_bytes(6));
        foreach ([
            'packages/plugins/i18n' => ['name' => 'strapi/i18n', 'extra' => ['strapi' => ['kind' => 'plugin', 'name' => 'i18n']]],
            'packages/providers/upload-local' => ['name' => 'strapi/provider-upload-local', 'extra' => ['strapi' => ['main' => 'X']]],
            'packages/utils/api-tests' => ['name' => 'strapi/api-tests'],
        ] as $path => $composer) {
            mkdir("{$this->dir}/{$path}", 0o777, true);
            file_put_contents("{$this->dir}/{$path}/composer.json", (string) json_encode($composer));
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testListsTheReplacedPackagesInsideTheBundle(): void
    {
        $info = ['name' => 'hynding/strapi-php', 'replace' => ['strapi/i18n' => 'self.version', 'strapi/provider-upload-local' => 'self.version']];

        $bundled = GetEnabledPlugins::bundledPackages($this->dir, $info);

        // strapi/api-tests sits in packages/ but is not replaced (not part of the bundle)
        self::assertSame(['strapi/i18n', 'strapi/provider-upload-local'], array_keys($bundled));
        self::assertSame(realpath("{$this->dir}/packages/plugins/i18n"), $bundled['strapi/i18n']['path']);
        self::assertSame('plugin', $bundled['strapi/i18n']['info']['extra']['strapi']['kind']);
    }

    public function testAPackageWithoutReplaceIsNotABundle(): void
    {
        self::assertSame([], GetEnabledPlugins::bundledPackages($this->dir, ['name' => 'acme/app']));
        self::assertSame([], GetEnabledPlugins::bundledPackages($this->dir . '/packages', ['replace' => ['strapi/i18n' => '*']]));
    }
}
