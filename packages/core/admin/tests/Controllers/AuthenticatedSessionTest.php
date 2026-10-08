<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Controllers;

require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Controllers\AuthenticatedSession;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/** Port of server/src/controllers/__tests__/authenticated-session.test.ts. */
final class AuthenticatedSessionTest extends TestCase
{
    private static ?Strapi $strapi = null;

    /** @var array<string, mixed> */
    private static array $user = [];

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedAdminApp::boot();
        self::$user = BootedAdminApp::createUser(self::$strapi, ['email' => 'sessions@strapi.io']);
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

    protected function setUp(): void
    {
        self::strapi()->db()->query('admin::session')->deleteMany([]);
    }

    /** @param array<string, mixed> $query */
    private static function ctx(?string $sessionId, string $method = 'GET', array $query = []): Context
    {
        $ctx = BootedAdminApp::ctx($method, '/admin/users/me/sessions', null, [], $query);
        $ctx->state()->set('user', self::$user);
        if ($sessionId !== null) {
            $ctx->state()->set('session', ['id' => $sessionId]);
        }

        return $ctx;
    }

    private static function newSession(string $deviceId): string
    {
        return self::strapi()->sessionManager()('admin')->generateRefreshToken((string) self::$user['id'], $deviceId, [
            'type' => 'refresh',
            'metadata' => ['deviceName' => "Device {$deviceId}", 'loginAt' => '2026-01-01T00:00:00.000Z'],
        ])['sessionId'];
    }

    public function testListReturnsSanitizedSessionsSortedForDisplay(): void
    {
        $older = self::newSession('device-a');
        $current = self::newSession('device-b');

        $ctx = self::ctx($current);
        (new AuthenticatedSession(self::strapi()))->list($ctx);

        $data = $ctx->body()['data'];
        self::assertSame([$current, $older], array_column($data, 'id'), 'current session first');
        self::assertTrue($data[0]['current']);
        self::assertFalse($data[1]['current']);
        self::assertSame('Device device-b', $data[0]['deviceName']);
        self::assertSame('2026-01-01T00:00:00.000Z', $data[0]['loginAt']);
        self::assertArrayNotHasKey('userId', $data[0], 'no internal fields');
    }

    public function testRevokesASessionOwnedByTheCurrentUser(): void
    {
        $sessionId = self::newSession('device-a');
        $ctx = self::ctx(null, 'DELETE');
        $ctx->setParams(['sessionId' => $sessionId]);

        (new AuthenticatedSession(self::strapi()))->revoke($ctx);

        self::assertSame('{"data":{}}', json_encode($ctx->body()));
        self::assertFalse(self::strapi()->sessionManager()('admin')->isSessionActive($sessionId));
    }

    public function testRevokeReturns404WhenTheSessionDoesNotExist(): void
    {
        $ctx = self::ctx(null, 'DELETE');
        $ctx->setParams(['sessionId' => 'missing']);

        (new AuthenticatedSession(self::strapi()))->revoke($ctx);

        self::assertSame(404, $ctx->status());
        self::assertSame('Session not found', $ctx->body()['error']['message']);
    }

    public function testRevokeAllInvalidatesEverySessionByDefault(): void
    {
        $current = self::newSession('device-a');
        self::newSession('device-b');

        (new AuthenticatedSession(self::strapi()))->revokeAll(self::ctx($current, 'DELETE'));

        self::assertSame([], self::strapi()->sessionManager()('admin')->listSessions((string) self::$user['id']));
    }

    public function testRevokeAllKeepsTheCurrentSessionWhenKeepCurrentIsTrue(): void
    {
        $current = self::newSession('device-a');
        self::newSession('device-b');

        (new AuthenticatedSession(self::strapi()))->revokeAll(self::ctx($current, 'DELETE', ['keepCurrent' => 'true']));

        self::assertSame([$current], array_column(self::strapi()->sessionManager()('admin')->listSessions((string) self::$user['id']), 'sessionId'));
    }
}
