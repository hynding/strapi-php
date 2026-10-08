<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Tests\Controllers\Validation;

require_once __DIR__ . '/../../BootedApp.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Controllers\Auth;
use Strapi\Plugin\UsersPermissions\Tests\BootedApp;
use Strapi\Utils\Errors\ValidationError;

/**
 * Port of server/src/controllers/validation/__tests__/auth.test.js: the auth controller's
 * register / resetPassword / changePassword / refresh validation. Upstream mocks the user and jwt
 * services and the database; here they are the booted app's, and `ctx.send` is the response body.
 */
final class AuthTest extends TestCase
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
        self::strapi()->db()->query('plugin::users-permissions.user')->deleteMany();
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

    /** @param array<string, mixed> $body @param array<string, mixed> $state */
    private static function ctx(array $body, array $state = ['auth' => []]): Context
    {
        return BootedApp::ctx('POST', 'http://localhost:1337/api/auth/local/register', $body, [], [], [], $state);
    }

    // register

    /** @return iterable<string, array{string}> */
    public static function registerCases(): iterable
    {
        yield 'Accepts valid registration with a typical password' => ['Testpassword1!'];
        yield 'Password is exactly 72 bytes with valid ASCII characters' => [str_repeat('a', 72)];
        yield 'Password is exactly 72 bytes with a mix of multibyte and single-byte characters' => [str_repeat('a', 69) . '测'];
    }

    #[DataProvider('registerCases')]
    public function testRegister(string $password): void
    {
        $ctx = self::ctx(['username' => 'testuser', 'email' => 'test@example.com', 'password' => $password]);

        self::controller()->register($ctx);

        self::assertSame(200, $ctx->status());
        self::assertIsString($ctx->body()['jwt'] ?? null);
        self::assertSame('test@example.com', $ctx->body()['user']['email'] ?? null);
    }

    public function testThrowsValidationErrorWhenPassedExtraFieldsWhenAllowedFieldIsUndefined(): void
    {
        $this->setConfig('plugin::users-permissions.register', []);
        $ctx = self::ctx(['confirmed' => true, 'username' => 'testuser', 'email' => 'test@example.com', 'password' => 'Testpassword1!']);

        $this->assertThrowsValidationError(static fn () => self::controller()->register($ctx), 'Invalid parameters: confirmed');
        self::assertNull($ctx->body());
    }

    public function testThrowsValidationErrorWhenPassedExtraFieldsWhenAllowedFieldIsEmpty(): void
    {
        $this->setConfig('plugin::users-permissions.register', ['allowedFields' => []]);
        $ctx = self::ctx(['confirmed' => true, 'username' => 'testuser', 'email' => 'test@example.com', 'password' => 'Testpassword1!']);

        $this->assertThrowsValidationError(static fn () => self::controller()->register($ctx), 'Invalid parameters: confirmed');
        self::assertNull($ctx->body());
    }

    public function testAllowsExceptionsFromConfigRegisterAllowedFields(): void
    {
        $this->setConfig('plugin::users-permissions.register', ['allowedFields' => ['confirmed']]);
        $ctx = self::ctx(['confirmed' => true, 'username' => 'testuser', 'email' => 'test@example.com', 'password' => 'Testpassword1!']);

        self::controller()->register($ctx);

        self::assertSame(200, $ctx->status());
        self::assertTrue($ctx->body()['user']['confirmed'] ?? null);
    }

    private function useCustomPasswordRule(): void
    {
        $this->setConfig('plugin::users-permissions.validationRules', [
            'validatePassword' => static function (string $value): bool {
                // Custom validation logic: at least 1 uppercase, 1 lowercase, and 1 number
                $hasUpperCase = preg_match('/[A-Z]/', $value) === 1;
                $hasLowerCase = preg_match('/[a-z]/', $value) === 1;
                $hasNumber = preg_match('/[0-9]/', $value) === 1;

                return $hasUpperCase && $hasLowerCase && $hasNumber && strlen($value) >= 6;
            },
        ]);
    }

    public function testPasswordDoesNotFollowCustomValidationPattern(): void
    {
        $this->useCustomPasswordRule();
        $ctx = self::ctx(['username' => 'testuser', 'email' => 'test@example.com', 'password' => 'TestingPassword']);

        $this->assertThrowsValidationError(static fn () => self::controller()->register($ctx), 'Password validation failed.');
        self::assertNull($ctx->body());
    }

    public function testPasswordFollowsCustomValidationPattern(): void
    {
        $this->useCustomPasswordRule();
        $ctx = self::ctx(['username' => 'testuser', 'email' => 'test@example.com', 'password' => 'Password123']);

        self::controller()->register($ctx);

        self::assertSame(200, $ctx->status());
    }

    /** @return iterable<string, array{string}> */
    public static function tooLongRegisterPasswords(): iterable
    {
        yield 'Password is exactly 73 bytes with valid ASCII characters' => ['a' . str_repeat('b', 72)];
        // `\uD83D` (half of a surrogate pair) reaches the server as U+FFFD (3 bytes), see strapi::body
        yield 'Password is 73 bytes but contains a character cut in half (UTF-8)' => ['a' . str_repeat('b', 70) . "=\u{FFFD}"];
        yield 'Password is 73 bytes with a three-byte character' => [str_repeat('a', 70) . '测'];
    }

    #[DataProvider('tooLongRegisterPasswords')]
    public function testRegisterRejectsPasswordsOver72Bytes(string $password): void
    {
        $ctx = self::ctx(['username' => 'testuser', 'email' => 'test@example.com', 'password' => $password]);

        $this->assertThrowsValidationError(static fn () => self::controller()->register($ctx), 'Password must be less than 73 bytes');
        self::assertNull($ctx->body());
    }

    // resetPassword

    /** @return iterable<string, array{array<string, string>, string|null}> */
    public static function resetPasswordCases(): iterable
    {
        yield 'Fails if passwords do not match' => [['password' => 'NewPassword123', 'passwordConfirmation' => 'DifferentPassword123', 'code' => 'valid-reset-token'], 'Passwords do not match'];
        yield 'Fails if reset token is invalid' => [['password' => 'NewPassword123', 'passwordConfirmation' => 'NewPassword123', 'code' => 'invalid-reset-token'], 'Incorrect code provided'];
        yield 'Successfully resets the password with valid input' => [['password' => 'NewPassword123', 'passwordConfirmation' => 'NewPassword123', 'code' => 'valid-reset-token'], null];
        yield 'Successfully resets the password when password is exactly 72 bytes' => [['password' => str_repeat('a', 72), 'passwordConfirmation' => str_repeat('a', 72), 'code' => 'valid-reset-token'], null];
        yield 'Fails if password exceeds 72 bytes' => [['password' => str_repeat('a', 73), 'passwordConfirmation' => str_repeat('a', 73), 'code' => 'valid-reset-token'], 'Password must be less than 73 bytes'];
    }

    /** @param array<string, string> $body */
    #[DataProvider('resetPasswordCases')]
    public function testResetPassword(array $body, ?string $expectedMessage): void
    {
        $user = BootedApp::createUser(self::strapi(), ['resetPasswordToken' => 'valid-reset-token']);
        $ctx = self::ctx($body);

        if ($expectedMessage !== null) {
            $this->assertThrowsValidationError(static fn () => self::controller()->resetPassword($ctx), $expectedMessage);
            self::assertNull($ctx->body());

            return;
        }

        self::controller()->resetPassword($ctx);

        self::assertIsString($ctx->body()['jwt'] ?? null);
        self::assertSame($user['id'], $ctx->body()['user']['id'] ?? null);
        self::assertArrayNotHasKey('resetPasswordToken', $ctx->body()['user']);
        $updated = self::strapi()->db()->query('plugin::users-permissions.user')->findOne(['where' => ['id' => $user['id']]]);
        self::assertNull($updated['resetPasswordToken'] ?? null);
        self::assertTrue(password_verify($body['password'], (string) ($updated['password'] ?? '')));
    }

    // changePassword

    /** @return iterable<string, array{array<string, string>, string|null}> */
    public static function changePasswordCases(): iterable
    {
        yield 'Fails if current password is incorrect' => [['currentPassword' => 'WrongPassword123', 'password' => 'NewPassword123', 'passwordConfirmation' => 'NewPassword123'], 'The provided current password is invalid'];
        yield 'Fails if new password is the same as the current password' => [['currentPassword' => 'CorrectPassword123', 'password' => 'CorrectPassword123', 'passwordConfirmation' => 'CorrectPassword123'], 'Your new password must be different than your current password'];
        yield 'Successfully changes the password with valid input' => [['currentPassword' => 'CorrectPassword123', 'password' => 'NewPassword123', 'passwordConfirmation' => 'NewPassword123'], null];
        yield 'Successfully changes the password when password is exactly 72 bytes' => [['currentPassword' => 'CorrectPassword123', 'password' => str_repeat('a', 72), 'passwordConfirmation' => str_repeat('a', 72)], null];
        yield 'Fails if password exceeds 72 bytes' => [['currentPassword' => 'CorrectPassword123', 'password' => str_repeat('a', 73), 'passwordConfirmation' => str_repeat('a', 73)], 'Password must be less than 73 bytes'];
    }

    /** @param array<string, string> $body */
    #[DataProvider('changePasswordCases')]
    public function testChangePassword(array $body, ?string $expectedMessage): void
    {
        $user = BootedApp::createUser(self::strapi(), ['password' => 'CorrectPassword123']);
        $ctx = self::ctx($body, ['user' => ['id' => $user['id']]]);

        if ($expectedMessage !== null) {
            $this->assertThrowsValidationError(static fn () => self::controller()->changePassword($ctx), $expectedMessage);
            self::assertNull($ctx->body());

            return;
        }

        self::controller()->changePassword($ctx);

        self::assertIsString($ctx->body()['jwt'] ?? null);
        self::assertSame($user['id'], $ctx->body()['user']['id'] ?? null);
        $updated = self::strapi()->db()->query('plugin::users-permissions.user')->findOne(['where' => ['id' => $user['id']]]);
        self::assertTrue(password_verify($body['password'], (string) ($updated['password'] ?? '')));
    }

    // refresh mode session invalidation

    public function testChangePasswordInvalidatesAllSessionsInRefreshMode(): void
    {
        $this->setConfig('plugin::users-permissions.jwtManagement', 'refresh');
        $user = BootedApp::createUser(self::strapi(), ['password' => 'CorrectPassword123']);
        $sessions = self::strapi()->sessionManager()('users-permissions');
        $sessions->generateRefreshToken((string) $user['id'], 'device-a', ['type' => 'refresh']);
        $sessions->generateRefreshToken((string) $user['id'], 'device-b', ['type' => 'refresh']);

        $ctx = self::ctx(['currentPassword' => 'CorrectPassword123', 'password' => 'NewPassword123', 'passwordConfirmation' => 'NewPassword123'], ['user' => ['id' => $user['id']]]);
        self::controller()->changePassword($ctx);

        // Should invalidate all sessions, then open a single new one
        $remaining = $sessions->listSessions((string) $user['id']);
        self::assertCount(1, $remaining);
        self::assertNotContains($remaining[0]['deviceId'] ?? null, ['device-a', 'device-b']);
        self::assertIsString($ctx->body()['jwt'] ?? null);
        self::assertIsString($ctx->body()['refreshToken'] ?? null);
    }

    public function testResetPasswordInvalidatesAllSessionsInRefreshMode(): void
    {
        $this->setConfig('plugin::users-permissions.jwtManagement', 'refresh');
        $user = BootedApp::createUser(self::strapi(), ['resetPasswordToken' => 'valid-reset-token']);
        $sessions = self::strapi()->sessionManager()('users-permissions');
        $sessions->generateRefreshToken((string) $user['id'], 'device-a', ['type' => 'refresh']);

        $ctx = self::ctx(['password' => 'NewPassword123', 'passwordConfirmation' => 'NewPassword123', 'code' => 'valid-reset-token']);
        self::controller()->resetPassword($ctx);

        $remaining = $sessions->listSessions((string) $user['id']);
        self::assertCount(1, $remaining);
        self::assertNotSame('device-a', $remaining[0]['deviceId'] ?? null);
        self::assertIsString($ctx->body()['refreshToken'] ?? null);
    }

    // refresh

    public function testRefreshReturnsNotFoundWhenJwtManagementIsNotRefreshMode(): void
    {
        $this->setConfig('plugin::users-permissions.jwtManagement', 'legacy-support');
        $ctx = self::ctx(['refreshToken' => 'token']);

        self::controller()->refresh($ctx);

        self::assertSame(404, $ctx->status());
    }

    public function testRefreshReturnsBadRequestWhenRefreshTokenIsMissing(): void
    {
        $this->setConfig('plugin::users-permissions.jwtManagement', 'refresh');
        $ctx = self::ctx([]);

        self::controller()->refresh($ctx);

        self::assertSame(400, $ctx->status());
        self::assertSame('Missing refresh token', $ctx->body()['error']['message'] ?? null);
    }

    public function testRefreshReturnsBadRequestWhenRefreshTokenIsNotAString(): void
    {
        $this->setConfig('plugin::users-permissions.jwtManagement', 'refresh');
        $ctx = self::ctx(['refreshToken' => 123]);

        self::controller()->refresh($ctx);

        self::assertSame(400, $ctx->status());
        self::assertSame('Missing refresh token', $ctx->body()['error']['message'] ?? null);
    }

    private function assertThrowsValidationError(callable $fn, string $message): void
    {
        try {
            $fn();
            self::fail("expected ValidationError '{$message}'");
        } catch (ValidationError $e) {
            self::assertSame($message, $e->getMessage());
        }
    }
}
