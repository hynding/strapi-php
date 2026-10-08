<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Controllers;

require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Controllers\ContentApi;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Strapi;

/** Port of server/src/controllers/__tests__/content-api.test.ts, against a booted app. */
final class ContentApiTest extends TestCase
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

    public function testReturnContentApiLayoutSuccessfully(): void
    {
        $ctx = BootedAdminApp::ctx();
        (new ContentApi(self::strapi()))->getPermissions($ctx);

        self::assertSame(200, $ctx->status());
        self::assertSame(['data' => self::strapi()->contentAPI()->permissions->getActionsMap()], $ctx->body());
    }

    public function testReturnContentApiRoutesSuccessfully(): void
    {
        $ctx = BootedAdminApp::ctx();
        (new ContentApi(self::strapi()))->getRoutes($ctx);

        self::assertSame(200, $ctx->status());
        self::assertSame(['data' => self::strapi()->contentAPI()->getRoutesMap()], $ctx->body());
    }
}
