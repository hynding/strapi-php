<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Services;

require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Services\Homepage;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Strapi;
use Strapi\Utils\Zod\ZodError;

/** Port of server/src/services/__tests__/homepage.test.ts, against a booted app's core store. */
final class HomepageTest extends TestCase
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

    private static function service(): Homepage
    {
        return (self::$strapi ?? throw new \LogicException('not booted'))->service('admin::homepage');
    }

    public function testReturnsNullWhenNothingStored(): void
    {
        self::assertNull(self::service()->getHomepageLayout(1001));
    }

    public function testReturnsParsedLayoutWhenStoredValueExists(): void
    {
        $layout = ['version' => 2, 'widgets' => [['uid' => 'a', 'width' => 4]], 'updatedAt' => '2025-01-01T00:00:00.000Z'];
        self::$strapi?->store()->scoped(['type' => 'core', 'name' => 'admin'])->set(['key' => 'homepage-layout:1002', 'value' => $layout]);

        self::assertSame($layout, self::service()->getHomepageLayout(1002));
    }

    public function testCreatesNewLayoutWhenNoneExists(): void
    {
        $result = self::service()->updateHomepageLayout(1003, ['widgets' => [['uid' => 'plugin::a.w', 'width' => 8]]]);

        self::assertSame(1, $result['version']);
        self::assertSame([['uid' => 'plugin::a.w', 'width' => 8]], $result['widgets']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $result['updatedAt']);
        self::assertSame($result, self::service()->getHomepageLayout(1003));
    }

    public function testUpdatesExistingLayoutAndPersistsProvidedWidths(): void
    {
        self::service()->updateHomepageLayout(1004, ['widgets' => [['uid' => 'a', 'width' => 4]]]);

        $result = self::service()->updateHomepageLayout(1004, [
            'version' => 3,
            'widgets' => [['uid' => 'a', 'width' => 12], ['uid' => 'b', 'width' => 6]],
            'updatedAt' => '2025-02-02T00:00:00.000Z',
        ]);

        self::assertSame(['version' => 3, 'widgets' => [['uid' => 'a', 'width' => 12], ['uid' => 'b', 'width' => 6]], 'updatedAt' => '2025-02-02T00:00:00.000Z'], $result);
    }

    public function testRejectsAnInvalidWidth(): void
    {
        $this->expectException(ZodError::class);

        self::service()->updateHomepageLayout(1005, ['widgets' => [['uid' => 'a', 'width' => 5]]]);
    }

    public function testKeyStatisticsCountTokensAdminsAndWebhooks(): void
    {
        $stats = self::service()->getKeyStatistics();

        self::assertSame(['assets', 'contentTypes', 'components', 'locales', 'admins', 'webhooks', 'apiTokens'], array_keys($stats));
        self::assertSame(1, $stats['locales'], 'the i18n plugin is installed with its default locale');
        self::assertSame(0, $stats['webhooks']);
    }
}
