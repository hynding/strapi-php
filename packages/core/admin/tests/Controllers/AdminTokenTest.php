<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Controllers;

require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Controllers\AdminToken;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ApplicationError;

/** Port of server/src/controllers/__tests__/admin-token.test.ts, against a booted app. */
final class AdminTokenTest extends TestCase
{
    private static ?Strapi $strapi = null;

    /** @var array<string, array<string, mixed>> */
    private static array $users = [];

    private static int $counter = 0;

    public static function setUpBeforeClass(): void
    {
        $strapi = self::$strapi = BootedAdminApp::boot();
        $strapi->config()->set('admin.secrets.encryptionKey', 'test-encryption-key');

        $role = $strapi->service('admin::role')->create(['name' => 'token editors', 'description' => '']);
        $strapi->service('admin::role')->assignPermissions($role['id'], [['action' => 'admin::users.read']]);

        self::$users = [
            'owner' => BootedAdminApp::createUser($strapi, ['email' => 'owner@test.com', 'roles' => [$role['id']]]),
            'other' => BootedAdminApp::createUser($strapi, ['email' => 'other@test.com', 'roles' => [$role['id']]]),
            'super' => BootedAdminApp::createUser($strapi, ['email' => 'super@test.com']),
        ];
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    private static function controller(): AdminToken
    {
        return new AdminToken(self::$strapi ?? throw new \LogicException('not booted'));
    }

    /** @param array<string, mixed>|null $body @param array<string, string> $params */
    private static function ctx(string $as, ?array $body = null, array $params = []): Context
    {
        $ctx = BootedAdminApp::ctx('POST', '/admin/admin-tokens', $body);
        if ($body !== null) {
            $ctx->setRequestBody($body);
        }
        $ctx->setParams($params);
        $ctx->state()->set('user', self::$users[$as]);

        return $ctx;
    }

    /** @return array<string, mixed> */
    private static function createAs(string $as, array $body = []): array
    {
        $ctx = self::ctx($as, ['name' => 'admin-token-' . ++self::$counter, 'description' => '', ...$body]);
        self::controller()->create($ctx);
        self::assertSame(201, $ctx->status(), (string) json_encode($ctx->body()));

        return $ctx->body()['data'];
    }

    public function testCreatesSuccessfullyHardcodingKindAdminAndTheOwner(): void
    {
        $data = self::createAs('owner', ['adminPermissions' => [['action' => 'admin::users.read']]]);

        self::assertSame('admin', $data['kind']);
        self::assertSame(self::$users['owner']['id'], $data['adminUserOwner']['id']);
        self::assertSame(['admin::users.read'], array_column($data['adminPermissions'], 'action'));
    }

    public function testThrowsWhenNameIsAlreadyTaken(): void
    {
        $data = self::createAs('owner');

        $this->expectExceptionObject(new ApplicationError('Name already taken'));
        self::controller()->create(self::ctx('owner', ['name' => $data['name']]));
    }

    public function testRejectsContentApiFields(): void
    {
        foreach (['type' => ['read-only', 'Type is not allowed for admin tokens'], 'permissions' => [[], 'Permissions are not allowed for admin tokens']] as $key => [$value, $message]) {
            $ctx = self::ctx('owner', ['name' => 'x', $key => $value]);
            self::controller()->create($ctx);
            self::assertSame(400, $ctx->status());
            self::assertSame($message, $ctx->body()['error']['message']);
        }
    }

    public function testListOnlyReturnsAdminTokensVisibleToTheCaller(): void
    {
        $mine = self::createAs('owner');
        $theirs = self::createAs('other');

        $ctx = self::ctx('owner');
        self::controller()->list($ctx);
        $ids = array_column($ctx->body()['data'], 'id');

        self::assertContains($mine['id'], $ids);
        self::assertNotContains($theirs['id'], $ids);
    }

    public function testRevokeIsAllowedToTheOwnerAndSuperAdminsOnly(): void
    {
        $a = self::createAs('owner');
        $b = self::createAs('owner');

        $ctx = self::ctx('other', null, ['id' => (string) $a['id']]);
        self::controller()->revoke($ctx);
        self::assertSame(403, $ctx->status());

        $ctx = self::ctx('owner', null, ['id' => (string) $a['id']]);
        self::controller()->revoke($ctx);
        self::assertSame($a['id'], $ctx->body()['data']['id']);

        $ctx = self::ctx('super', null, ['id' => (string) $b['id']]);
        self::controller()->revoke($ctx);
        self::assertSame($b['id'], $ctx->body()['data']['id']);

        $ctx = self::ctx('super', null, ['id' => (string) $b['id']]);
        self::controller()->revoke($ctx);
        self::assertSame(404, $ctx->status());
    }

    public function testRegenerateIsOwnerOnlyAndSuperAdminDoesNotBypass(): void
    {
        $token = self::createAs('owner');

        foreach (['other', 'super'] as $as) {
            $ctx = self::ctx($as, null, ['id' => (string) $token['id']]);
            self::controller()->regenerate($ctx);
            self::assertSame(403, $ctx->status());
        }

        $ctx = self::ctx('owner', null, ['id' => (string) $token['id']]);
        self::controller()->regenerate($ctx);
        self::assertSame(201, $ctx->status());
        self::assertArrayHasKey('accessKey', $ctx->body()['data']);
    }

    public function testGetExposesTheKeyToTheOwnerOnly(): void
    {
        $token = self::createAs('owner');

        $ctx = self::ctx('owner', null, ['id' => (string) $token['id']]);
        self::controller()->get($ctx);
        self::assertSame($token['accessKey'], $ctx->body()['data']['accessKey']);

        $ctx = self::ctx('super', null, ['id' => (string) $token['id']]);
        self::controller()->get($ctx);
        self::assertSame($token['id'], $ctx->body()['data']['id']);
        self::assertArrayNotHasKey('accessKey', $ctx->body()['data']);

        $ctx = self::ctx('other', null, ['id' => (string) $token['id']]);
        self::controller()->get($ctx);
        self::assertSame(404, $ctx->status());
    }

    public function testUpdateIsAllowedToTheOwnerAndSuperAdminsOnly(): void
    {
        $token = self::createAs('owner');

        $ctx = self::ctx('other', ['description' => 'x'], ['id' => (string) $token['id']]);
        self::controller()->update($ctx);
        self::assertSame(403, $ctx->status());

        $ctx = self::ctx('super', ['description' => ' by super '], ['id' => (string) $token['id']]);
        self::controller()->update($ctx);
        self::assertSame('by super', $ctx->body()['data']['description']);

        $ctx = self::ctx('owner', ['description' => 'x'], ['id' => '424242']);
        self::controller()->update($ctx);
        self::assertSame(404, $ctx->status());
    }

    public function testGetOwnerPermissions(): void
    {
        $token = self::createAs('owner');

        foreach (['owner', 'super'] as $as) {
            $ctx = self::ctx($as, null, ['id' => (string) $token['id']]);
            self::controller()->getOwnerPermissions($ctx);
            self::assertSame(['admin::users.read'], array_column($ctx->body()['data'], 'action'));
            self::assertSame(['id', 'action', 'actionParameters', 'subject', 'properties', 'conditions'], array_keys($ctx->body()['data'][0]));
        }

        $ctx = self::ctx('other', null, ['id' => (string) $token['id']]);
        self::controller()->getOwnerPermissions($ctx);
        self::assertSame(403, $ctx->status());

        $ctx = self::ctx('owner', null, ['id' => '424242']);
        self::controller()->getOwnerPermissions($ctx);
        self::assertSame(404, $ctx->status());
        self::assertSame('apiToken.notFound', $ctx->body()['error']['message']);
    }
}
