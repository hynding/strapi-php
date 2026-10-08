<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Controllers;

require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Controllers\User;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Errors\YupValidationError;

/** Port of server/src/controllers/__tests__/user.test.ts. */
final class UserTest extends TestCase
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
    }

    private static function controller(): User
    {
        return new User(self::strapi());
    }

    /** @param array<string, mixed>|null $body @param array<string, string> $params @param array<string, mixed>|null $user */
    private static function ctx(string $method, ?array $body = null, array $params = [], ?array $user = null): Context
    {
        $ctx = BootedAdminApp::ctx($method, '/admin/users', $body);
        $ctx->setParams($params);
        if ($user !== null) {
            $ctx->state()->set('user', $user);
        }

        return $ctx;
    }

    private static function editorRoleId(): mixed
    {
        return self::strapi()->db()->query('admin::role')->findOne(['where' => ['code' => 'strapi-editor']])['id'] ?? null;
    }

    public function testCreateFailsIfTheUserAlreadyExists(): void
    {
        BootedAdminApp::createUser(self::strapi(), ['email' => 'kai@doe.com']);

        $this->expectException(ApplicationError::class);
        $this->expectExceptionMessage('Email already taken');
        self::controller()->create(self::ctx('POST', ['email' => 'kai@doe.com', 'firstname' => 'Kai', 'lastname' => 'Doe', 'roles' => [self::editorRoleId()]]));
    }

    public function testCreatesAUserWithACamelCaseEmailAndKeepsTheRegistrationToken(): void
    {
        $ctx = self::ctx('POST', ['email' => 'Kai@Doe.com', 'firstname' => 'Kai', 'lastname' => 'Doe', 'roles' => [self::editorRoleId()]]);

        self::controller()->create($ctx);

        self::assertSame(201, $ctx->status());
        $data = $ctx->body()['data'];
        self::assertSame('kai@doe.com', $data['email']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) $data['registrationToken']);
        self::assertArrayNotHasKey('password', $data);
    }

    public function testFindsAUserByItsId(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'kai@doe.com']);
        $ctx = self::ctx('GET', null, ['id' => (string) $user['id']]);

        self::controller()->findOne($ctx);

        self::assertSame($user['id'], $ctx->body()['data']['id']);
    }

    public function testFindOneUserNotFound(): void
    {
        $ctx = self::ctx('GET', null, ['id' => '999999']);

        self::controller()->findOne($ctx);

        self::assertSame(404, $ctx->status());
        self::assertSame('User does not exist', $ctx->body()['error']['message']);
    }

    public function testUpdateUserNotFound(): void
    {
        $ctx = self::ctx('PUT', ['firstname' => 'Ada'], ['id' => '999999']);

        self::controller()->update($ctx);

        self::assertSame(404, $ctx->status());
    }

    public function testUpdateValidationError(): void
    {
        $this->expectException(YupValidationError::class);
        self::controller()->update(self::ctx('PUT', ['firstname' => 21], ['id' => '1']));
    }

    public function testUpdateLowercasesTheEmailBeforeTheUniquenessCheck(): void
    {
        BootedAdminApp::createUser(self::strapi(), ['email' => 'taken@doe.com']);
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'kai@doe.com']);

        try {
            self::controller()->update(self::ctx('PUT', ['email' => 'Taken@Doe.com'], ['id' => (string) $user['id']]));
            self::fail('expected an ApplicationError');
        } catch (ApplicationError $e) {
            self::assertSame('A user with this email address already exists', $e->getMessage());
        }

        $ctx = self::ctx('PUT', ['email' => 'New@Doe.com'], ['id' => (string) $user['id']]);
        self::controller()->update($ctx);
        self::assertSame('new@doe.com', $ctx->body()['data']['email']);
    }

    public function testDeletesAUserAndHandlesAMissingOne(): void
    {
        BootedAdminApp::createUser(self::strapi(), ['email' => 'admin@doe.com']);
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'kai@doe.com']);

        $ctx = self::ctx('DELETE', null, ['id' => (string) $user['id']]);
        self::controller()->deleteOne($ctx);
        self::assertSame($user['id'], $ctx->body()['data']['id']);

        $ctx = self::ctx('DELETE', null, ['id' => (string) $user['id']]);
        self::controller()->deleteOne($ctx);
        self::assertSame(404, $ctx->status());
        self::assertSame('User not found', $ctx->body()['error']['message']);
    }

    public function testBatchDeletePreventsDeletingYourself(): void
    {
        $me = BootedAdminApp::createUser(self::strapi(), ['email' => 'me@doe.com']);

        $this->expectExceptionMessage('You cannot delete your own user');
        self::controller()->deleteMany(self::ctx('POST', ['ids' => [$me['id']]], [], $me));
    }

    public function testBatchDeletesUsers(): void
    {
        $me = BootedAdminApp::createUser(self::strapi(), ['email' => 'me@doe.com']);
        $a = BootedAdminApp::createUser(self::strapi(), ['email' => 'a@doe.com']);
        $b = BootedAdminApp::createUser(self::strapi(), ['email' => 'b@doe.com']);
        $ctx = self::ctx('POST', ['ids' => [$a['id'], $b['id']]], [], $me);

        self::controller()->deleteMany($ctx);

        self::assertSame([$a['id'], $b['id']], array_column($ctx->body()['data'], 'id'));
    }
}
