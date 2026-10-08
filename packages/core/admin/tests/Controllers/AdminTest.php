<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Controllers;

require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Controllers\Admin;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Strapi;

/** Port of server/src/controllers/__tests__/admin.test.ts (community edition). */
final class AdminTest extends TestCase
{
    private static ?Strapi $strapi = null;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedAdminApp::boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    private static function strapi(): Strapi
    {
        return self::$strapi ?? throw new \LogicException('not booted');
    }

    public function testInitReturnsTheUuidAndIfTheAppHasAdmins(): void
    {
        self::strapi()->config()->set('uuid', 'foo');
        self::strapi()->db()->query('admin::user')->deleteMany([]);

        $result = (new Admin(self::strapi()))->init();
        self::assertSame(['uuid' => 'foo', 'hasAdmin' => false, 'menuLogo' => null, 'authLogo' => null], $result['data']);

        BootedAdminApp::createUser(self::strapi(), ['email' => 'init@strapi.io']);
        self::strapi()->config()->set('packageJsonStrapi.telemetryDisabled', true);
        $result = (new Admin(self::strapi()))->init();
        self::assertSame(['uuid' => false, 'hasAdmin' => true, 'menuLogo' => null, 'authLogo' => null], $result['data']);
    }

    public function testInitReturnsTheLogoUrls(): void
    {
        self::strapi()->store()->set(['type' => 'core', 'name' => 'admin', 'key' => 'project-settings', 'value' => ['menuLogo' => ['url' => '/uploads/logo.png', 'name' => 'logo.png', 'hash' => 'x']]]);
        try {
            self::assertSame('/uploads/logo.png', (new Admin(self::strapi()))->init()['data']['menuLogo']);
            self::assertSame(['name' => 'logo.png', 'url' => '/uploads/logo.png'], (new Admin(self::strapi()))->getProjectSettings()['menuLogo']);
        } finally {
            self::strapi()->store()->delete(['type' => 'core', 'name' => 'admin', 'key' => 'project-settings']);
        }
    }

    public function testTelemetryPropertiesReturns204WhenTelemetryIsDisabled(): void
    {
        $ctx = BootedAdminApp::ctx();

        self::assertNull((new Admin(self::strapi()))->telemetryProperties($ctx));
        self::assertSame(204, $ctx->status());
    }

    public function testInformationReturnsApplicationInformation(): void
    {
        self::strapi()->config()->set('info.strapi', '5.56.0');
        $data = (new Admin(self::strapi()))->information()['data'];

        self::assertSame(['currentEnvironment', 'autoReload', 'strapiVersion', 'dependencies', 'projectId', 'nodeVersion', 'communityEdition', 'useYarn'], array_keys($data));
        self::assertSame('5.56.0', $data['strapiVersion']);
        self::assertTrue($data['communityEdition']);
    }

    public function testCeGetProjectTypeAlwaysReturnsIsTrialFalse(): void
    {
        $data = (new Admin(self::strapi()))->getProjectType()['data'];

        self::assertFalse($data['isEE']);
        self::assertFalse($data['isTrial']);
        self::assertSame([], $data['features']);
        self::assertSame(['enabled' => false], $data['ai']);
    }

    public function testPluginsListsTheNonCorePlugins(): void
    {
        self::strapi()->config()->set('enabledPlugins', [
            'upload' => ['info' => ['name' => 'upload']],
            'seo' => ['info' => ['displayName' => 'SEO', 'description' => 'Meta tags', 'packageName' => '@strapi/plugin-seo']],
        ]);
        $ctx = BootedAdminApp::ctx();

        (new Admin(self::strapi()))->plugins($ctx);

        self::assertSame(['plugins' => [['name' => 'seo', 'displayName' => 'SEO', 'description' => 'Meta tags', 'packageName' => '@strapi/plugin-seo']]], $ctx->body());
    }
}
