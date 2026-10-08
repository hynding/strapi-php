<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Strategies;

require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Strategies\Admin;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Strapi;

/** Port of server/src/strategies/__tests__/admin.test.ts (sessions-based access tokens). */
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

    /** @param array<string, mixed> $user @return array{access: string, sessionId: string} */
    private static function login(array $user): array
    {
        $manager = self::strapi()->sessionManager()('admin');
        $refresh = $manager->generateRefreshToken((string) $user['id'], 'device-1');
        $access = $manager->generateAccessToken($refresh['token']);

        return ['access' => $access['token'] ?? '', 'sessionId' => $refresh['sessionId']];
    }

    private static function authenticate(?string $authorization): array
    {
        $ctx = BootedAdminApp::ctx('GET', '/admin/users/me', null, $authorization === null ? [] : ['Authorization' => $authorization]);

        return Admin::authenticate($ctx, self::strapi()) + ['ctx' => $ctx];
    }

    public function testAuthenticatesAValidAccessTokenAndActiveSession(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'strategy@strapi.io']);
        ['access' => $access, 'sessionId' => $sessionId] = self::login($user);

        $result = self::authenticate("Bearer {$access}");

        self::assertTrue($result['authenticated']);
        self::assertSame($user['id'], $result['credentials']['id'] ?? null);
        self::assertNotNull($result['ability']);
        $state = $result['ctx']->state();
        self::assertSame($user['id'], $state->get('user')['id'] ?? null);
        self::assertSame($result['ability'], $state->get('userAbility'));
        self::assertSame(['id' => $sessionId], $state->get('session'));
        self::assertSame('admin', Admin::strategy(self::strapi())['name']);
    }

    public function testFailsWhenTheAuthorizationHeaderIsMissing(): void
    {
        self::assertFalse(self::authenticate(null)['authenticated']);
    }

    public function testFailsOnAnInvalidAuthorizationHeader(): void
    {
        self::assertFalse(self::authenticate('Basic abc')['authenticated']);
        self::assertFalse(self::authenticate('Bearer a b')['authenticated']);
    }

    public function testFailsOnAnInvalidBearerToken(): void
    {
        self::assertFalse(self::authenticate('Bearer not-a-jwt')['authenticated']);
    }

    public function testFailsWhenTheSessionIsNotActive(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'revoked@strapi.io']);
        ['access' => $access] = self::login($user);
        self::strapi()->sessionManager()('admin')->invalidateRefreshToken((string) $user['id']);

        self::assertFalse(self::authenticate("Bearer {$access}")['authenticated']);
    }

    public function testFailsForAnInactiveUser(): void
    {
        BootedAdminApp::createUser(self::strapi(), ['email' => 'other-super-admin@strapi.io']);
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'inactive@strapi.io']);
        ['access' => $access] = self::login($user);
        self::strapi()->db()->query('admin::user')->update(['where' => ['id' => $user['id']], 'data' => ['isActive' => false]]);

        self::assertFalse(self::authenticate("Bearer {$access}")['authenticated']);
    }

    public function testFailsForANonExistingUser(): void
    {
        $manager = self::strapi()->sessionManager()('admin');
        $refresh = $manager->generateRefreshToken('999999', 'device-1');
        $access = $manager->generateAccessToken($refresh['token']);

        self::assertFalse(self::authenticate('Bearer ' . ($access['token'] ?? ''))['authenticated']);
    }
}
