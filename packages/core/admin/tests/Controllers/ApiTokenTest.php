<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Controllers;

require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Controllers\ApiToken;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Errors\ValidationError;

/** Port of server/src/controllers/__tests__/api-token.test.ts, against a booted app. */
final class ApiTokenTest extends TestCase
{
    private static ?Strapi $strapi = null;

    /** @var array<string, mixed> */
    private static array $user = [];

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedAdminApp::boot();
        self::$strapi->config()->set('admin.secrets.encryptionKey', 'test-encryption-key');
        self::$user = BootedAdminApp::createUser(self::$strapi, ['email' => 'controller@test.com']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    private static function controller(): ApiToken
    {
        return new ApiToken(self::$strapi ?? throw new \LogicException('not booted'));
    }

    /** @param array<string, mixed>|null $body @param array<string, string> $params */
    private static function ctx(?array $body = null, array $params = []): Context
    {
        $ctx = BootedAdminApp::ctx('POST', '/admin/api-tokens', $body);
        if ($body !== null) {
            $ctx->setRequestBody($body);
        }
        $ctx->setParams($params);
        $ctx->state()->set('user', self::$user);

        return $ctx;
    }

    /** @return array<string, mixed> */
    private static function create(array $body): array
    {
        $ctx = self::ctx($body);
        self::controller()->create($ctx);
        self::assertSame(201, $ctx->status());

        return $ctx->body()['data'];
    }

    public function testCreateApiTokenSuccessfullyTrimmingNameAndDescription(): void
    {
        $data = self::create(['name' => '  api-token_tests-name  ', 'description' => ' api-token_tests-description ', 'type' => 'read-only', 'expiresAt' => 1234]);

        self::assertSame('api-token_tests-name', $data['name']);
        self::assertSame('api-token_tests-description', $data['description']);
        self::assertSame('content-api', $data['kind']);
        self::assertArrayHasKey('accessKey', $data);
        self::assertNull($data['expiresAt'], 'a received expiresAt is ignored');
    }

    public function testFailsIfApiTokenAlreadyExists(): void
    {
        self::create(['name' => 'duplicate', 'type' => 'read-only']);

        $this->expectExceptionObject(new ApplicationError('Name already taken'));
        self::controller()->create(self::ctx(['name' => 'duplicate', 'type' => 'read-only']));
    }

    public function testCreateApiTokenWithValidLifespanAndThrowsWithInvalidOrNegativeLifespan(): void
    {
        $data = self::create(['name' => 'with-lifespan', 'type' => 'read-only', 'lifespan' => 7 * 24 * 3600 * 1000]);
        self::assertNotNull($data['expiresAt']);

        foreach ([1234, -(7 * 24 * 3600 * 1000)] as $lifespan) {
            try {
                self::controller()->create(self::ctx(['name' => "invalid {$lifespan}", 'type' => 'read-only', 'lifespan' => $lifespan]));
                self::fail('expected a ValidationError');
            } catch (ValidationError $e) {
                self::assertSame('ValidationError', $e->name);
            }
        }
    }

    public function testDoesNotForwardAdminFieldsForLegacyKind(): void
    {
        $data = self::create(['name' => 'with-admin-fields', 'type' => 'read-only', 'adminPermissions' => [['action' => 'admin::users.read']], 'adminUserOwner' => 1]);

        self::assertArrayNotHasKey('adminPermissions', $data);
        self::assertArrayNotHasKey('adminUserOwner', $data);
    }

    public function testListGetRegenerateUpdateAndRevoke(): void
    {
        $token = self::create(['name' => 'lifecycle', 'type' => 'read-only']);
        $id = (string) $token['id'];

        $ctx = self::ctx();
        self::controller()->list($ctx);
        self::assertContains($token['id'], array_column($ctx->body()['data'], 'id'));

        $ctx = self::ctx(null, ['id' => $id]);
        self::controller()->get($ctx);
        self::assertSame($token['accessKey'], $ctx->body()['data']['accessKey']);

        $ctx = self::ctx(null, ['id' => $id]);
        self::controller()->regenerate($ctx);
        self::assertSame(201, $ctx->status());
        self::assertNotSame($token['accessKey'], $ctx->body()['data']['accessKey']);

        $ctx = self::ctx(['name' => ' renamed ', 'description' => null], ['id' => $id]);
        self::controller()->update($ctx);
        self::assertSame('renamed', $ctx->body()['data']['name']);
        self::assertSame('', $ctx->body()['data']['description']);

        $ctx = self::ctx(null, ['id' => $id]);
        self::controller()->revoke($ctx);
        self::assertSame($token['id'], $ctx->body()['data']['id']);

        $ctx = self::ctx(null, ['id' => $id]);
        self::controller()->revoke($ctx);
        self::assertSame(['data' => null], $ctx->body(), 'does not return an error if the resource does not exist');
    }

    public function testNotFoundOnGetRegenerateAndUpdate(): void
    {
        foreach (['get', 'regenerate'] as $action) {
            $ctx = self::ctx(null, ['id' => '424242']);
            self::controller()->{$action}($ctx);
            self::assertSame(404, $ctx->status());
            self::assertSame('API Token not found', $ctx->body()['error']['message']);
        }

        $ctx = self::ctx(['name' => 'x'], ['id' => '424242']);
        self::controller()->update($ctx);
        self::assertSame(404, $ctx->status());
    }

    public function testUpdateFailsIfTheNameIsAlreadyTaken(): void
    {
        self::create(['name' => 'taken-name', 'type' => 'read-only']);
        $other = self::create(['name' => 'other-name', 'type' => 'read-only']);

        $this->expectExceptionObject(new ApplicationError('Name already taken'));
        self::controller()->update(self::ctx(['name' => 'taken-name'], ['id' => (string) $other['id']]));
    }

    public function testUpdateRejectsInjectedAdminFieldsAndKind(): void
    {
        $token = self::create(['name' => 'injection', 'type' => 'read-only']);

        foreach (['adminUserOwner' => 1, 'adminPermissions' => [], 'kind' => 'admin'] as $key => $value) {
            try {
                self::controller()->update(self::ctx([$key => $value], ['id' => (string) $token['id']]));
                self::fail("expected a ValidationError for {$key}");
            } catch (ValidationError $e) {
                self::assertSame("this field has unspecified keys: {$key}", $e->getMessage());
            }
        }
    }
}
