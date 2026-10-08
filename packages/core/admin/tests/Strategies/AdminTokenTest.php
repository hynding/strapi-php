<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Strategies;

require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Strategies\AdminToken;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\UnauthorizedError;

/**
 * Port of server/src/strategies/__tests__/admin-token.test.ts, against real admin tokens of a
 * booted app instead of a mocked `admin::api-token-admin` service.
 */
final class AdminTokenTest extends TestCase
{
    private static ?Strapi $strapi = null;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedAdminApp::boot();
        self::$strapi->config()->set('admin.secrets.encryptionKey', 'test-encryption-key');
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

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} [owner, token] */
    private static function ownerAndToken(): array
    {
        static $i = 0;
        $i++;
        $owner = BootedAdminApp::createUser(self::strapi(), ['email' => "owner{$i}@test.com"]);
        $token = self::strapi()->service('admin::api-token-admin')->create([
            'name' => "admin-token-{$i}",
            'description' => '',
            'adminPermissions' => [['action' => 'admin::users.read']],
        ], $owner);

        return [$owner, $token];
    }

    /** @return array<string, mixed> */
    private static function authenticate(?string $authorization, ?\Strapi\Core\Services\Server\Context &$ctx = null): array
    {
        $ctx = BootedAdminApp::ctx('GET', '/admin/users', null, $authorization === null ? [] : ['Authorization' => $authorization]);

        return AdminToken::authenticate($ctx, self::strapi());
    }

    public function testAuthenticatesAnAdminTokenWhenOwnerIsActiveAndNotBlocked(): void
    {
        [$owner, $token] = self::ownerAndToken();

        $response = self::authenticate("bearer {$token['accessKey']}", $ctx);

        self::assertTrue($response['authenticated']);
        self::assertSame($token['id'], $response['credentials']['id']);
        self::assertSame($owner['id'], $response['user']['id']);
        self::assertTrue($response['ability']->can('admin::users.read'));
        self::assertFalse($response['ability']->can('admin::users.delete'));
        self::assertSame($owner['id'], $ctx?->state()->get('user')['id']);
        self::assertSame($response['ability'], $ctx?->state()->get('userAbility'));
    }

    public function testFailsToAuthenticateWhenLoadedOwnerIsActiveIsFalse(): void
    {
        [$owner, $token] = self::ownerAndToken();
        self::strapi()->db()->query('admin::user')->update(['where' => ['id' => $owner['id']], 'data' => ['isActive' => false]]);

        $response = self::authenticate("bearer {$token['accessKey']}");

        self::assertFalse($response['authenticated']);
        self::assertInstanceOf(UnauthorizedError::class, $response['error']);
        self::assertSame('Token owner is deactivated', $response['error']->getMessage());
    }

    public function testFailsToAuthenticateWhenLoadedOwnerIsBlocked(): void
    {
        [$owner, $token] = self::ownerAndToken();
        self::strapi()->db()->query('admin::user')->update(['where' => ['id' => $owner['id']], 'data' => ['blocked' => true]]);

        $response = self::authenticate("bearer {$token['accessKey']}");

        self::assertFalse($response['authenticated']);
        self::assertSame('Token owner is deactivated', $response['error']->getMessage());
    }

    public function testFailsToAuthenticateIfTheAuthorizationHeaderIsMissing(): void
    {
        self::assertSame(['authenticated' => false], self::authenticate(null));
    }

    public function testExpiredTokenReturnsAuthenticatedFalseWithUnauthorizedError(): void
    {
        [, $token] = self::ownerAndToken();
        self::strapi()->db()->query('admin::api-token')->update(['where' => ['id' => $token['id']], 'data' => ['expiresAt' => new \DateTimeImmutable('-1 minute')]]);

        $response = self::authenticate("bearer {$token['accessKey']}");

        self::assertFalse($response['authenticated']);
        self::assertSame('Token expired', $response['error']->getMessage());
    }

    public function testRejectsAContentApiTokenOnAnAdminRoute(): void
    {
        $token = self::strapi()->service('admin::api-token-content-api')->create(['name' => 'content-kind-check', 'description' => '', 'type' => 'read-only']);

        self::assertSame(['authenticated' => false], self::authenticate("bearer {$token['accessKey']}"));
    }

    public function testVerifyReturnsWithoutThrowingForAValidTokenWithNoExpiry(): void
    {
        AdminToken::verify(['credentials' => ['id' => 1, 'kind' => 'admin', 'expiresAt' => null]]);
        self::addToAssertionCount(1);
    }

    public function testVerifyReturnsWithoutThrowingForAValidTokenWithAFutureExpiry(): void
    {
        AdminToken::verify(['credentials' => ['id' => 1, 'kind' => 'admin', 'expiresAt' => (new \DateTimeImmutable('+1 hour'))->format(DATE_ATOM)]]);
        self::addToAssertionCount(1);
    }

    public function testVerifyThrowsUnauthorizedErrorIfCredentialsAreMissing(): void
    {
        $this->expectException(UnauthorizedError::class);
        $this->expectExceptionMessage('Token not found');

        AdminToken::verify([]);
    }

    public function testVerifyThrowsUnauthorizedErrorForAnExpiredToken(): void
    {
        $this->expectException(UnauthorizedError::class);
        $this->expectExceptionMessage('Token expired');

        AdminToken::verify(['credentials' => ['id' => 1, 'kind' => 'admin', 'expiresAt' => (new \DateTimeImmutable('-1 hour'))->format(DATE_ATOM)]]);
    }
}
