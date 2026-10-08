<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Services;

require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Services\Metrics;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Strapi;

/**
 * Port of server/src/services/__tests__/metrics.test.ts. Telemetry is a no-op in the PHP port, so
 * the tests check the queries the events are built from and that sending does not fail.
 */
final class MetricsTest extends TestCase
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

    public function testSendDidInviteUser(): void
    {
        BootedAdminApp::createUser(self::strapi(), ['email' => 'metrics@strapi.io']);
        (new Metrics(self::strapi()))->sendDidInviteUser();

        self::assertSame(3, self::strapi()->service('admin::role')->count(), 'numberOfRoles');
        self::assertSame(1, self::strapi()->service('admin::user')->count(), 'numberOfUsers');
    }

    public function testSendDidUpdateRolePermissions(): void
    {
        (new Metrics(self::strapi()))->sendDidUpdateRolePermissions();
        $this->addToAssertionCount(1);
    }

    public function testDidChangeInterfaceLanguage(): void
    {
        (new Metrics(self::strapi()))->sendDidChangeInterfaceLanguage();
        self::assertContains('en', self::strapi()->service('admin::user')->getLanguagesInUse());
    }

    public function testStartCronSchedulesTheProjectInformation(): void
    {
        (new Metrics(self::strapi()))->startCron(self::strapi());

        self::assertContains('sendProjectInformation', array_column(self::strapi()->cron()->jobs(), 'name'));
    }
}
