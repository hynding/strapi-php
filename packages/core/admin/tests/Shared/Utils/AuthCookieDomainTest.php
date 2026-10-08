<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Shared\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Shared\Utils\AuthCookieDomain;

/** Port of shared/utils/__tests__/auth-cookie-domain.test.ts. */
final class AuthCookieDomainTest extends TestCase
{
    public function testDefaultsToAHostOnlyCookieWhenUnsetOrBlank(): void
    {
        self::assertNull(AuthCookieDomain::resolveAuthCookieDomain());
        self::assertNull(AuthCookieDomain::resolveAuthCookieDomain(''));
        self::assertNull(AuthCookieDomain::resolveAuthCookieDomain('   '));
    }

    public function testReturnsAConfiguredBareHostName(): void
    {
        self::assertSame('strapi.test', AuthCookieDomain::resolveAuthCookieDomain('strapi.test'));
        self::assertSame('.strapi.test', AuthCookieDomain::resolveAuthCookieDomain('.strapi.test'));
        self::assertSame('cms.strapi.test', AuthCookieDomain::resolveAuthCookieDomain('cms.strapi.test'));
    }

    public function testRejectsDomainsWithSchemesPathsPortsOrAttributeSeparators(): void
    {
        $warnings = [];
        $warn = static function (string $m) use (&$warnings): void {
            $warnings[] = $m;
        };
        self::assertNull(AuthCookieDomain::resolveAuthCookieDomain('https://strapi.test', $warn));
        self::assertNull(AuthCookieDomain::resolveAuthCookieDomain('strapi.test/admin', $warn));
        self::assertNull(AuthCookieDomain::resolveAuthCookieDomain('strapi.test:1337', $warn));
        self::assertNull(AuthCookieDomain::resolveAuthCookieDomain('strapi.test;HttpOnly', $warn));
        self::assertCount(4, $warnings);
    }

    public function testRoutesTheWarningThroughTheProvidedWarnCallback(): void
    {
        $warnings = [];
        self::assertNull(AuthCookieDomain::resolveAuthCookieDomain('strapi.test:1337', static function (string $m) use (&$warnings): void {
            $warnings[] = $m;
        }));
        self::assertStringContainsString('strapi.test:1337', $warnings[0]);
    }
}
