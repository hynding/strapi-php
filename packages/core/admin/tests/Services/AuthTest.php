<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Services;

require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/services/__tests__/auth.test.ts, against a booted app (real `admin::user`
 * rows) instead of mocked queries.
 */
final class AuthTest extends TestCase
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

    /** @return list<array{0: string, 1: mixed}> */

    public function testCheckCredentialsFailsOnNotFoundUserWithoutLeakingInfo(): void
    {
        $result = self::strapi()->service('admin::auth')->checkCredentials(['email' => 'test@strapi.io', 'password' => 'pcw123']);

        self::assertSame([null, false, ['message' => 'Invalid credentials']], $result);
    }

    public function testCheckCredentialsFailsWhenPasswordIsInvalid(): void
    {
        BootedAdminApp::createUser(self::strapi(), ['email' => 'test@strapi.io', 'password' => 'Password123']);

        $result = self::strapi()->service('admin::auth')->checkCredentials(['email' => 'test@strapi.io', 'password' => 'wrong']);

        self::assertSame([null, false, ['message' => 'Invalid credentials']], $result);
    }

    public function testCheckCredentialsFailsWhenUserIsNotActive(): void
    {
        BootedAdminApp::createUser(self::strapi(), ['email' => 'test@strapi.io', 'password' => 'Password123', 'isActive' => false]);

        $result = self::strapi()->service('admin::auth')->checkCredentials(['email' => 'test@strapi.io', 'password' => 'Password123']);

        self::assertSame([null, false, ['message' => 'User not active']], $result);
    }

    public function testCheckCredentialsReturnsUserWhenAllChecksPass(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'test@strapi.io', 'password' => 'Password123']);

        $result = self::strapi()->service('admin::auth')->checkCredentials(['email' => 'test@strapi.io', 'password' => 'Password123']);

        self::assertNull($result[0]);
        self::assertSame($user['id'], $result[1]['id'] ?? null);
    }

    public function testValidatePasswordComparesWithTheHash(): void
    {
        $auth = self::strapi()->service('admin::auth');
        $hash = $auth->hashPassword('Password123');

        self::assertMatchesRegularExpression('/^\$2[aby]\$10\$/', $hash);
        self::assertTrue($auth->validatePassword('Password123', $hash));
        self::assertFalse($auth->validatePassword('password', $hash));
        // bcryptjs hashes ($2a$/$2b$) are accepted
        self::assertTrue($auth->validatePassword('Password123', '$2b$10$' . substr($hash, 7)));
    }

    public function testForgotPasswordOnlyRunsForActiveUsers(): void
    {
        BootedAdminApp::createUser(self::strapi(), ['email' => 'inactive@strapi.io', 'isActive' => false]);

        self::strapi()->service('admin::auth')->forgotPassword(['email' => 'inactive@strapi.io']);

        $user = self::strapi()->db()->query('admin::user')->findOne(['where' => ['email' => 'inactive@strapi.io']]);
        self::assertNull($user['resetPasswordToken'] ?? null);
    }

    public function testForgotPasswordReturnsSilentlyWhenTheUserIsNotFound(): void
    {
        self::strapi()->service('admin::auth')->forgotPassword(['email' => 'nobody@strapi.io']);
        $this->addToAssertionCount(1);
    }

    public function testAssignsANewResetTokenWithAnExpiryAndRecordsTheAuditEvent(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'test@strapi.io']);
        $events = [];
        $unsubscribe = self::strapi()->eventHub()->on('admin-user.password-reset.create', static function (mixed $payload) use (&$events): void {
            $events[] = $payload;
        });

        $token = self::strapi()->service('admin::auth')->assignResetPasswordToken($user['id']);
        $unsubscribe();

        $updated = self::strapi()->db()->query('admin::user')->findOne(['where' => ['id' => $user['id']]]);
        self::assertSame($token, $updated['resetPasswordToken']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $token);
        $expiresAt = new \DateTimeImmutable((string) $updated['resetPasswordTokenExpiresAt']);
        self::assertEqualsWithDelta(time() + 3600, $expiresAt->getTimestamp(), 5);

        self::assertCount(1, $events);
        self::assertSame($user['id'], $events[0]['userId']);
        self::assertSame('test@strapi.io', $events[0]['email']);
        self::assertArrayNotHasKey('resetPasswordToken', $events[0], 'never the token');
    }

    public function testResetPasswordTokenTtlComesFromConfig(): void
    {
        self::strapi()->config()->set('admin.forgotPassword.expiresIn', '15m');
        try {
            self::assertSame(15 * 60 * 1000, self::strapi()->service('admin::auth')->getResetPasswordTokenTTL());
            self::strapi()->config()->set('admin.forgotPassword.expiresIn', 'nonsense');
            self::assertSame(3600 * 1000, self::strapi()->service('admin::auth')->getResetPasswordTokenTTL());
        } finally {
            self::strapi()->config()->set('admin.forgotPassword.expiresIn', null);
        }
    }

    public function testResetPasswordChecksTheUserIsActive(): void
    {
        BootedAdminApp::createUser(self::strapi(), ['email' => 'test@strapi.io', 'isActive' => false, 'resetPasswordToken' => '123']);

        $this->expectException(ApplicationError::class);
        self::strapi()->service('admin::auth')->resetPassword(['resetPasswordToken' => '123', 'password' => 'Test1234']);
    }

    public function testResetPasswordFailsIfUserIsNotFound(): void
    {
        $this->expectException(ApplicationError::class);
        self::strapi()->service('admin::auth')->resetPassword(['resetPasswordToken' => 'nope', 'password' => 'Test1234']);
    }

    public function testResetPasswordFailsIfTheTokenHasExpired(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), [
            'email' => 'test@strapi.io',
            'resetPasswordToken' => '123',
            'resetPasswordTokenExpiresAt' => new \DateTimeImmutable('-1 minute'),
        ]);

        try {
            self::strapi()->service('admin::auth')->resetPassword(['resetPasswordToken' => '123', 'password' => 'Test1234']);
            self::fail('expected an ApplicationError');
        } catch (ApplicationError $e) {
            self::assertSame('This reset password token has expired', $e->getMessage());
        }

        $updated = self::strapi()->db()->query('admin::user')->findOne(['where' => ['id' => $user['id']]]);
        self::assertNull($updated['resetPasswordToken']);
    }

    public function testResetPasswordFailsAndClearsALegacyTokenWithNoExpiry(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'test@strapi.io', 'resetPasswordToken' => '123']);

        try {
            self::strapi()->service('admin::auth')->resetPassword(['resetPasswordToken' => '123', 'password' => 'Test1234']);
            self::fail('expected an ApplicationError');
        } catch (ApplicationError $e) {
            self::assertSame('This reset password token has expired', $e->getMessage());
        }

        $updated = self::strapi()->db()->query('admin::user')->findOne(['where' => ['id' => $user['id']]]);
        self::assertNull($updated['resetPasswordToken']);
        self::assertNull($updated['resetPasswordTokenExpiresAt']);
    }

    public function testResetPasswordChangesThePasswordAndClearsTheToken(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), [
            'email' => 'test@strapi.io',
            'password' => 'OldPassword1',
            'resetPasswordToken' => '123',
            'resetPasswordTokenExpiresAt' => new \DateTimeImmutable('+1 hour'),
        ]);

        $result = self::strapi()->service('admin::auth')->resetPassword(['resetPasswordToken' => '123', 'password' => 'Test1234']);

        self::assertSame($user['id'], $result['id'] ?? null);
        $updated = self::strapi()->db()->query('admin::user')->findOne(['where' => ['id' => $user['id']]]);
        self::assertNull($updated['resetPasswordToken']);
        self::assertTrue(self::strapi()->service('admin::auth')->validatePassword('Test1234', (string) $updated['password']));
    }
}
