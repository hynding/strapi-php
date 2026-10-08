<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Tests\Controllers;

require_once __DIR__ . '/../BootedApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Services\SessionManager\OriginSessionManager;
use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Controllers\Auth;
use Strapi\Plugin\UsersPermissions\Tests\BootedApp;

/**
 * Port of server/src/controllers/__tests__/auth-sessions.test.js. Upstream mocks the session
 * manager; here sessions are created with the booted app's `users-permissions` origin and the
 * assertions read them back.
 */
final class AuthSessionsTest extends TestCase
{
    private static ?Strapi $strapi = null;

    /** @var array<string, mixed> */
    private array $restoreConfig = [];

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedApp::boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    protected function setUp(): void
    {
        $this->setConfig('plugin::users-permissions.jwtManagement', 'refresh');
        $this->setConfig('plugin::users-permissions.sessions', ['httpOnly' => false]);
    }

    protected function tearDown(): void
    {
        foreach ($this->restoreConfig as $path => $value) {
            self::strapi()->config()->set($path, $value);
        }
        $this->restoreConfig = [];
    }

    private static function strapi(): Strapi
    {
        return self::$strapi ?? throw new \LogicException('not booted');
    }

    private function setConfig(string $path, mixed $value): void
    {
        if (!array_key_exists($path, $this->restoreConfig)) {
            $this->restoreConfig[$path] = self::strapi()->config()->get($path);
        }
        self::strapi()->config()->set($path, $value);
    }

    private static function controller(): Auth
    {
        return new Auth(self::strapi());
    }

    private static function sessions(): OriginSessionManager
    {
        return self::strapi()->sessionManager()('users-permissions');
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, string> $params
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private static function ctx(array $state = [], array $params = [], array $body = [], array $headers = [], string $scheme = 'http'): Context
    {
        return BootedApp::ctx('POST', "{$scheme}://localhost:1337/api/auth/logout", $body, $headers, [], $params, $state);
    }

    /** @return list<string> the user's active session ids */
    private static function activeSessionIds(int|string $userId): array
    {
        return array_map(static fn (array $s): string => (string) $s['sessionId'], self::sessions()->listSessions((string) $userId));
    }

    private function legacyMode(): void
    {
        $this->setConfig('plugin::users-permissions.jwtManagement', 'legacy-support');
    }

    // getSessions

    public function testGetSessionsReturns404OutsideRefreshMode(): void
    {
        $this->legacyMode();
        $ctx = self::ctx(['user' => ['id' => 1]]);

        self::controller()->getSessions($ctx);

        self::assertSame(404, $ctx->status());
    }

    public function testGetSessionsRequiresAuthentication(): void
    {
        $ctx = self::ctx();

        self::controller()->getSessions($ctx);

        self::assertSame(401, $ctx->status());
        self::assertSame('Missing authentication', $ctx->body()['error']['message'] ?? null);
    }

    public function testGetSessionsReturnsSanitizedSessionsWithTheCurrentSessionFlagged(): void
    {
        $user = BootedApp::createUser(self::strapi());
        $other = self::sessions()->generateRefreshToken((string) $user['id'], 'device-b', [
            'type' => 'refresh',
            'metadata' => ['loginAt' => '2026-06-09T08:00:00.000Z', 'deviceName' => 'Safari'],
        ]);
        $current = self::sessions()->generateRefreshToken((string) $user['id'], 'device-a', [
            'type' => 'refresh',
            'metadata' => ['loginAt' => '2026-06-10T08:00:00.000Z', 'deviceName' => 'Chrome'],
        ]);

        $ctx = self::ctx(['user' => ['id' => $user['id']], 'session' => ['id' => $current['sessionId']]]);
        self::controller()->getSessions($ctx);

        $data = $ctx->body()['data'] ?? [];
        self::assertCount(2, $data);
        self::assertSame($current['sessionId'], $data[0]['id']);
        self::assertTrue($data[0]['current']);
        self::assertSame('Chrome', $data[0]['deviceName']);
        self::assertSame($other['sessionId'], $data[1]['id']);
        self::assertFalse($data[1]['current']);
        self::assertSame('Safari', $data[1]['deviceName']);
    }

    // revokeSession

    public function testRevokeSessionReturns404OutsideRefreshMode(): void
    {
        $this->legacyMode();
        $ctx = self::ctx(['user' => ['id' => 1]], ['sessionId' => 'session-1']);

        self::controller()->revokeSession($ctx);

        self::assertSame(404, $ctx->status());
    }

    public function testRevokeSessionRequiresAuthentication(): void
    {
        $ctx = self::ctx([], ['sessionId' => 'session-1']);

        self::controller()->revokeSession($ctx);

        self::assertSame(401, $ctx->status());
        self::assertSame('Missing authentication', $ctx->body()['error']['message'] ?? null);
    }

    public function testRevokeSessionReturns404WhenTheSessionDoesNotExist(): void
    {
        $ctx = self::ctx(['user' => ['id' => 42]], ['sessionId' => 'missing']);

        self::controller()->revokeSession($ctx);

        self::assertSame(404, $ctx->status());
        self::assertSame('Session not found', $ctx->body()['error']['message'] ?? null);
    }

    public function testRevokeSessionRevokesTheRequestedSession(): void
    {
        $user = BootedApp::createUser(self::strapi());
        $session = self::sessions()->generateRefreshToken((string) $user['id'], 'device-a', ['type' => 'refresh']);
        $ctx = self::ctx(['user' => ['id' => $user['id']]], ['sessionId' => $session['sessionId']]);

        self::controller()->revokeSession($ctx);

        self::assertEquals(['data' => new \stdClass()], $ctx->body());
        self::assertSame([], self::activeSessionIds($user['id']));
    }

    // logout

    public function testLogoutReturns404OutsideRefreshMode(): void
    {
        $this->legacyMode();
        $ctx = self::ctx(['user' => ['id' => 5], 'session' => ['id' => 'session-current']]);

        self::controller()->logout($ctx);

        self::assertSame(404, $ctx->status());
    }

    public function testLogoutRequiresAuthentication(): void
    {
        $ctx = self::ctx();

        self::controller()->logout($ctx);

        self::assertSame(401, $ctx->status());
        self::assertSame('Missing authentication', $ctx->body()['error']['message'] ?? null);
    }

    /** @return array{0: array<string, mixed>, 1: array{token: string, sessionId: string}, 2: array{token: string, sessionId: string}} */
    private static function userWithTwoSessions(): array
    {
        $user = BootedApp::createUser(self::strapi());
        $a = self::sessions()->generateRefreshToken((string) $user['id'], 'device-a', ['type' => 'refresh']);
        $b = self::sessions()->generateRefreshToken((string) $user['id'], 'device-123', ['type' => 'refresh']);

        return [$user, $a, $b];
    }

    public function testLogoutRevokesEverySessionWhenScopeIsAll(): void
    {
        [$user, $a] = self::userWithTwoSessions();
        $ctx = self::ctx(['user' => ['id' => $user['id']], 'session' => ['id' => $a['sessionId']]], [], ['scope' => 'all']);

        self::controller()->logout($ctx);

        self::assertSame([], self::activeSessionIds($user['id']));
        self::assertSame(['ok' => true], $ctx->body());
    }

    public function testLogoutRevokesADeviceFamilyWhenDeviceIdIsProvided(): void
    {
        [$user, $a] = self::userWithTwoSessions();
        $ctx = self::ctx(['user' => ['id' => $user['id']], 'session' => ['id' => $a['sessionId']]], [], ['deviceId' => 'device-123']);

        self::controller()->logout($ctx);

        self::assertSame([$a['sessionId']], self::activeSessionIds($user['id']));
    }

    public function testLogoutRevokesOnlyTheCurrentSessionByDefault(): void
    {
        [$user, $a, $b] = self::userWithTwoSessions();
        $ctx = self::ctx(['user' => ['id' => $user['id']], 'session' => ['id' => $a['sessionId']]]);

        self::controller()->logout($ctx);

        self::assertSame([$b['sessionId']], self::activeSessionIds($user['id']));
    }

    public function testLogoutResolvesTheCurrentSessionFromTheRefreshTokenWhenNeeded(): void
    {
        [$user, $a, $b] = self::userWithTwoSessions();
        $ctx = self::ctx(['user' => ['id' => $user['id']]], [], ['refreshToken' => $b['token']]);

        self::controller()->logout($ctx);

        self::assertSame([$a['sessionId']], self::activeSessionIds($user['id']));
    }

    public function testLogoutFallsBackToAFullLogoutWhenTheCurrentSessionCannotBeIdentified(): void
    {
        [$user] = self::userWithTwoSessions();
        $ctx = self::ctx(['user' => ['id' => $user['id']]]);

        self::controller()->logout($ctx);

        self::assertSame([], self::activeSessionIds($user['id']));
    }

    public function testLogoutClearsTheRefreshCookieWhenHttpOnlySessionsAreEnabled(): void
    {
        $this->setConfig('plugin::users-permissions.sessions', [
            'httpOnly' => true,
            'cookie' => [
                'name' => 'custom_refresh',
                'path' => '/custom-path',
                'domain' => 'example.com',
                'sameSite' => 'strict',
                'secure' => true,
                'maxAge' => 3600000,
            ],
        ]);
        [$user, $a] = self::userWithTwoSessions();
        $ctx = self::ctx(['user' => ['id' => $user['id']], 'session' => ['id' => $a['sessionId']]], scheme: 'https');

        self::controller()->logout($ctx);

        $cookies = array_values(array_filter(
            $ctx->responseHeaders()['set-cookie'] ?? [],
            static fn (string $c): bool => str_starts_with($c, 'custom_refresh='),
        ));
        self::assertCount(1, $cookies);
        $cookie = strtolower($cookies[0]);
        self::assertStringStartsWith('custom_refresh=;', $cookie);
        self::assertStringContainsString('path=/custom-path', $cookie);
        self::assertStringContainsString('domain=example.com', $cookie);
        self::assertStringContainsString('samesite=strict', $cookie);
        self::assertStringContainsString('secure', $cookie);
        self::assertStringContainsString('expires=thu, 01 jan 1970 00:00:00 gmt', $cookie);
        self::assertStringNotContainsString('max-age', $cookie);
    }
}
