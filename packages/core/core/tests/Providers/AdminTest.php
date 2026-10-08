<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Providers;

use Strapi\Core\Loaders\Admin as LoadAdmin;
use Strapi\Core\Tests\BootedAppTestCase;

/** providers/admin.ts + loaders/admin.ts: the installed `strapi/admin` package is the admin module. */
final class AdminTest extends BootedAppTestCase
{
    public function testTheInstalledAdminPackageIsLoaded(): void
    {
        $strapi = self::strapi();

        self::assertNotNull(LoadAdmin::adminServerFile($strapi));
        self::assertTrue(LoadAdmin::hasAdminModule($strapi));

        // the admin package's schema, not the PHP-port fallback (which has no reset-token expiry)
        self::assertArrayHasKey('resetPasswordTokenExpiresAt', $strapi->contentType('admin::user')->attributes);
        self::assertSame('admin', $strapi->contentType('admin::session')->plugin);
        self::assertNotNull($strapi->get('services')->get('admin::user'));
        self::assertSame('Reset password', $strapi->config()->get('admin.forgotPassword.emailTemplate.subject'), 'module config merged under the app config');

        // the fallbacks still cover what other packages would define
        self::assertNotNull($strapi->getModel('plugin::upload.file'));
        self::assertTrue($strapi->get('policies')->has('admin::isAuthenticatedAdmin'));
    }

    public function testTheAdminStrategyIsRegistered(): void
    {
        $names = array_column(self::strapi()->get('auth')->strategies()['admin'] ?? [], 'name');

        self::assertContains('admin', $names);
    }
}
