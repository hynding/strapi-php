<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Shared\Utils;

require_once __DIR__ . '/../../BootedAdminApp.php';
require_once __DIR__ . '/../../StubStrapi.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Shared\Utils\SessionAuth;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Admin\Tests\StubStrapi;
use Strapi\Core\Strapi;

/** Port of shared/utils/__tests__/session-auth.test.ts (plus the cookie-expiry helpers). */
final class SessionAuthTest extends TestCase
{
    private static ?Strapi $app = null;

    private Strapi $strapi;

    protected function setUp(): void
    {
        $this->strapi = StubStrapi::create();
    }

    public static function tearDownAfterClass(): void
    {
        self::$app?->destroy();
        self::$app = null;
    }

    public function testAccessCookieNameDefaultsToJwtToken(): void
    {
        self::assertSame('jwtToken', SessionAuth::getAccessCookieName($this->strapi));
    }

    public function testAccessCookieNameUsesTheConfig(): void
    {
        $this->strapi->config()->set('admin.auth.cookie.name', 'config_cookie_name');
        self::assertSame('config_cookie_name', SessionAuth::getAccessCookieName($this->strapi));
    }

    public function testAccessCookieNameDoesNotReadTheEnvironment(): void
    {
        putenv('STRAPI_ADMIN_AUTH_COOKIE_NAME=env_cookie_name');
        try {
            self::assertSame('jwtToken', SessionAuth::getAccessCookieName($this->strapi));
        } finally {
            putenv('STRAPI_ADMIN_AUTH_COOKIE_NAME');
        }
    }

    public function testAccessCookiePath(): void
    {
        self::assertSame('/admin', SessionAuth::getAccessCookiePath($this->strapi));
        $this->strapi->config()->set('admin.auth.cookie.path', '/strapi-de/admin');
        self::assertSame('/strapi-de/admin', SessionAuth::getAccessCookiePath($this->strapi));
    }

    public function testAccessCookieDomain(): void
    {
        self::assertNull(SessionAuth::getAccessCookieDomain($this->strapi));

        $this->strapi->config()->set('admin.auth.domain', 'legacy.strapi.test');
        self::assertSame('legacy.strapi.test', SessionAuth::getAccessCookieDomain($this->strapi), 'falls back to admin.auth.domain');

        $this->strapi->config()->set('admin.auth.cookie.domain', 'cookie.strapi.test');
        self::assertSame('cookie.strapi.test', SessionAuth::getAccessCookieDomain($this->strapi), 'prefers admin.auth.cookie.domain');
    }

    public function testRefreshCookieOptionsResolveTheDomainThroughTheSharedHelper(): void
    {
        $this->strapi->config()->set('admin.auth.cookie.domain', 'strapi.test');
        self::assertSame('strapi.test', SessionAuth::getRefreshCookieOptions($this->strapi)['domain']);
    }

    public function testRefreshCookieOptionsWarnViaStrapiLogOnAnInvalidDomain(): void
    {
        $logs = BootedAdminApp::recordLogs($this->strapi);
        $this->strapi->config()->set('admin.auth.cookie.domain', 'strapi.test:1337');

        self::assertNull(SessionAuth::getRefreshCookieOptions($this->strapi)['domain']);
        self::assertStringContainsString('strapi.test:1337', $logs->messages('warning')[0] ?? '');
    }

    public function testRefreshCookieSecureFlag(): void
    {
        $previous = getenv('NODE_ENV');
        try {
            putenv('NODE_ENV=development');
            self::assertFalse(SessionAuth::getRefreshCookieOptions($this->strapi, true)['secure']);
            putenv('NODE_ENV=production');
            self::assertTrue(SessionAuth::getRefreshCookieOptions($this->strapi, true)['secure']);
            self::assertFalse(SessionAuth::getRefreshCookieOptions($this->strapi, false)['secure'], 'production over http');
            $this->strapi->config()->set('admin.auth.cookie.secure', false);
            self::assertFalse(SessionAuth::getRefreshCookieOptions($this->strapi, true)['secure'], 'explicit config wins');
        } finally {
            putenv($previous === false ? 'NODE_ENV' : "NODE_ENV={$previous}");
        }
    }

    public function testCookieExpiryKeepsSessionCookiesAndBoundsRefreshCookies(): void
    {
        self::assertArrayNotHasKey('expires', SessionAuth::buildCookieOptionsWithExpiry($this->strapi, 'session', '2099-01-01T00:00:00.000Z'));

        $this->strapi->config()->set('admin.auth.sessions.idleRefreshTokenLifespan', 3600);
        $soon = gmdate('Y-m-d\TH:i:s.000\Z', time() + 60);
        $options = SessionAuth::buildCookieOptionsWithExpiry($this->strapi, 'refresh', $soon);
        self::assertLessThanOrEqual(60_000, $options['maxAge'], 'the absolute expiry is earlier than the idle one');

        $options = SessionAuth::buildCookieOptionsWithExpiry($this->strapi, 'refresh', '2099-01-01T00:00:00.000Z');
        self::assertEqualsWithDelta(3_600_000, $options['maxAge'], 2000);
    }

    public function testExtractDeviceParams(): void
    {
        self::assertSame(['deviceId' => 'd-1', 'rememberMe' => true], SessionAuth::extractDeviceParams(['deviceId' => 'd-1', 'rememberMe' => true]));
        $generated = SessionAuth::extractDeviceParams(null);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $generated['deviceId']);
        self::assertFalse($generated['rememberMe']);
    }

    private static function app(): Strapi
    {
        return self::$app ??= BootedAdminApp::boot();
    }

    /** @param array<string, mixed> $row */
    private static function session(array $row): void
    {
        self::app()->db()->query('admin::session')->create(['data' => [
            'sessionId' => 'session-1',
            'expiresAt' => new \DateTimeImmutable('+1 hour'),
            ...$row,
        ]]);
    }

    private static function clearSessions(): void
    {
        self::app()->db()->query('admin::session')->deleteMany([]);
    }

    public function testLogoutDeviceFallsBackToTheClientDeviceIdWithoutSessionId(): void
    {
        $logs = BootedAdminApp::recordLogs(self::app());
        self::assertSame('client-device', SessionAuth::resolveLogoutDeviceId(self::app(), '42', null, 'client-device'));
        self::assertNotEmpty($logs->messages('debug'));
    }

    public function testLogoutDeviceIsTheSessionDeviceIdWhenOwnedByTheAdminUser(): void
    {
        self::clearSessions();
        self::session(['userId' => '42', 'origin' => 'admin', 'deviceId' => 'sso-device']);
        self::assertSame('sso-device', SessionAuth::resolveLogoutDeviceId(self::app(), '42', 'session-1', 'client-device'));
    }

    public function testLogoutDeviceFallsBackWhenTheSessionIsNotOwned(): void
    {
        self::clearSessions();
        self::session(['userId' => '99', 'origin' => 'admin', 'deviceId' => 'other-device']);
        self::assertSame('client-device', SessionAuth::resolveLogoutDeviceId(self::app(), '42', 'session-1', 'client-device'));
        self::assertSame('client-device', SessionAuth::resolveLogoutDeviceId(self::app(), '42', 'missing', 'client-device'));
    }

    public function testLogoutDeviceFallsBackWhenTheSessionOriginIsNotAdmin(): void
    {
        self::clearSessions();
        self::session(['userId' => '42', 'origin' => 'users-permissions', 'deviceId' => 'up-device']);
        self::assertSame('client-device', SessionAuth::resolveLogoutDeviceId(self::app(), '42', 'session-1', 'client-device'));
    }
}
