<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Controllers;

require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Controllers\AuthenticatedUser;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/** Port of server/src/controllers/__tests__/authenticated-user.test.ts. */
final class AuthenticatedUserTest extends TestCase
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

    protected function setUp(): void
    {
        self::strapi()->db()->query('admin::user')->deleteMany([]);
        self::strapi()->db()->query('admin::session')->deleteMany([]);
    }

    /** @param array<string, mixed> $user @param array<string, mixed>|null $body */
    private static function ctx(array $user, ?array $body = null): Context
    {
        $ctx = BootedAdminApp::ctx($body === null ? 'GET' : 'PUT', '/admin/users/me', $body);
        $ctx->state()->set('user', self::strapi()->db()->query('admin::user')->findOne(['where' => ['id' => $user['id']], 'populate' => ['roles']]));

        return $ctx;
    }

    public function testGetMeReturnsTheSanitizedUser(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'me@strapi.io', 'password' => 'Password123']);
        $ctx = self::ctx($user);

        (new AuthenticatedUser(self::strapi()))->getMe($ctx);

        $data = $ctx->body()['data'];
        self::assertSame('me@strapi.io', $data['email']);
        self::assertArrayNotHasKey('password', $data);
        self::assertSame(['id', 'name', 'description', 'code'], array_keys($data['roles'][0]));
    }

    public function testGetOwnPermissionsMapsFindUserPermissionsThroughSanitizePermission(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'me@strapi.io']);
        $ctx = self::ctx($user);

        (new AuthenticatedUser(self::strapi()))->getOwnPermissions($ctx);

        $data = $ctx->body()['data'];
        self::assertNotEmpty($data, 'the super admin has permissions');
        self::assertSame(['id', 'action', 'actionParameters', 'subject', 'properties', 'conditions'], array_keys($data[0]));
    }

    public function testReturnsAValidationErrorWhenTheEmailIsUsedByAnotherAdmin(): void
    {
        BootedAdminApp::createUser(self::strapi(), ['email' => 'taken@strapi.io']);
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'me@strapi.io']);
        $ctx = self::ctx($user, ['email' => 'Taken@Strapi.io']);

        (new AuthenticatedUser(self::strapi()))->updateMe($ctx);

        self::assertSame(400, $ctx->status());
        self::assertSame(['email' => ['Email already taken']], $ctx->body()['error']['details']);
    }

    public function testLowercasesTheEmailBeforeValidationAndPersistence(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'me@strapi.io']);
        $ctx = self::ctx($user, ['email' => 'New@Strapi.io', 'firstname' => 'Kai']);

        (new AuthenticatedUser(self::strapi()))->updateMe($ctx);

        self::assertSame('new@strapi.io', $ctx->body()['data']['email']);
    }

    public function testReturnsBadRequestWhenCurrentPasswordDoesNotMatch(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'me@strapi.io', 'password' => 'Password123']);
        $ctx = self::ctx($user, ['currentPassword' => 'Wrong1234', 'password' => 'Password456']);

        (new AuthenticatedUser(self::strapi()))->updateMe($ctx);

        self::assertSame(400, $ctx->status());
        self::assertSame('ValidationError', $ctx->body()['error']['message']);
        self::assertSame(['currentPassword' => ['Invalid credentials']], $ctx->body()['error']['details']);
    }

    public function testInvalidatesRefreshTokensWhenThePasswordChanges(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'me@strapi.io', 'password' => 'Password123']);
        self::strapi()->sessionManager()('admin')->generateRefreshToken((string) $user['id'], 'device-1');
        $ctx = self::ctx($user, ['currentPassword' => 'Password123', 'password' => 'Password456']);

        (new AuthenticatedUser(self::strapi()))->updateMe($ctx);

        self::assertSame(200, $ctx->status());
        self::assertSame([], self::strapi()->sessionManager()('admin')->listSessions((string) $user['id']));
    }

    public function testDoesNotInvalidateSessionsWhenTheEmailIsAlreadyTaken(): void
    {
        BootedAdminApp::createUser(self::strapi(), ['email' => 'taken@strapi.io']);
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'me@strapi.io', 'password' => 'Password123']);
        self::strapi()->sessionManager()('admin')->generateRefreshToken((string) $user['id'], 'device-1');
        $ctx = self::ctx($user, ['email' => 'taken@strapi.io', 'currentPassword' => 'Password123', 'password' => 'Password456']);

        (new AuthenticatedUser(self::strapi()))->updateMe($ctx);

        self::assertSame(400, $ctx->status());
        self::assertCount(1, self::strapi()->sessionManager()('admin')->listSessions((string) $user['id']));
    }
}
