<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Services;

require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ValidationError;

/**
 * Port of server/src/services/__tests__/user.test.ts, against a booted app (real rows) instead of
 * mocked queries.
 */
final class UserTest extends TestCase
{
    private static ?Strapi $strapi = null;

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedAdminApp::boot();
        self::$strapi->sessionManager()->defineOrigin('admin', [
            'jwtSecret' => 'test',
            'accessTokenLifespan' => 1800,
            'maxRefreshTokenLifespan' => 86400,
            'idleRefreshTokenLifespan' => 3600,
            'maxSessionLifespan' => 86400,
            'idleSessionLifespan' => 3600,
        ]);
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

    /** @return array<string, mixed> */
    private static function role(string $code): array
    {
        return self::strapi()->db()->query('admin::role')->findOne(['where' => ['code' => $code]]) ?? throw new \LogicException("no role {$code}");
    }

    /**
     * @param list<string> $events
     * @return \ArrayObject<string, list<mixed>> payloads received, by event
     */
    private static function listen(array $events): \ArrayObject
    {
        $received = new \ArrayObject();
        foreach ($events as $event) {
            self::strapi()->eventHub()->on($event, static function (mixed $payload) use ($received, $event): void {
                $received[$event] = [...($received[$event] ?? []), $payload];
            });
        }

        return $received;
    }

    public function testSanitizeUserRemovesPasswordAndTokens(): void
    {
        $sanitized = self::strapi()->service('admin::user')->sanitizeUser([
            'id' => 1,
            'firstname' => 'Test',
            'password' => 'hash',
            'resetPasswordToken' => 'token',
            'resetPasswordTokenExpiresAt' => '2026-01-01',
            'registrationToken' => 'reg',
            'roles' => [['id' => 1, 'name' => 'Super Admin', 'code' => 'strapi-super-admin', 'description' => '', 'createdAt' => 'x']],
        ]);

        self::assertSame([
            'id' => 1,
            'firstname' => 'Test',
            'roles' => [['id' => 1, 'name' => 'Super Admin', 'description' => '', 'code' => 'strapi-super-admin']],
        ], $sanitized);
    }

    public function testCreatesAUserByMergingGivenAndDefaultAttributes(): void
    {
        $user = self::strapi()->service('admin::user')->create(['email' => 'new@strapi.io', 'firstname' => 'New']);

        self::assertFalse($user['isActive']);
        self::assertNull($user['username']);
        self::assertSame([], $user['roles']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', (string) $user['registrationToken']);
    }

    public function testCreatesAUserAndHashesThePassword(): void
    {
        $user = self::strapi()->service('admin::user')->create(['email' => 'new@strapi.io', 'password' => 'Password123']);

        self::assertNotSame('Password123', $user['password']);
        self::assertTrue(password_verify('Password123', (string) $user['password']));
    }

    public function testCreateFirstAdminRecordsTheSuperAdminInTheAuditLog(): void
    {
        $events = self::listen(['admin-user.create', 'user.create']);

        $user = self::strapi()->service('admin::user')->createFirstAdmin(['email' => 'first@strapi.io', 'firstname' => 'First', 'lastname' => 'Admin', 'password' => 'Password123']);

        self::assertTrue($user['isActive']);
        self::assertNull($user['registrationToken']);
        self::assertSame([self::role('strapi-super-admin')['id']], array_column($user['roles'], 'id'));
        self::assertSame([
            'userId' => $user['id'],
            'email' => 'first@strapi.io',
            'firstname' => 'First',
            'lastname' => 'Admin',
            'roles' => [self::role('strapi-super-admin')['id']],
            'isActive' => true,
        ], $events['admin-user.create'][0]);
        self::assertArrayNotHasKey('password', $events['user.create'][0]['user']);
    }

    public function testCreateFirstAdminRefusesASecondSuperAdmin(): void
    {
        self::strapi()->service('admin::user')->createFirstAdmin(['email' => 'first@strapi.io', 'firstname' => 'First']);

        $this->expectExceptionMessage('You cannot register a new super admin');
        self::strapi()->service('admin::user')->createFirstAdmin(['email' => 'second@strapi.io', 'firstname' => 'Second']);
    }

    public function testCountUsers(): void
    {
        BootedAdminApp::createUser(self::strapi(), ['email' => 'a@strapi.io']);
        BootedAdminApp::createUser(self::strapi(), ['email' => 'b@strapi.io', 'isActive' => false]);

        self::assertSame(2, self::strapi()->service('admin::user')->count());
        self::assertSame(1, self::strapi()->service('admin::user')->count(['isActive' => true]));
    }

    public function testUpdateHashesThePassword(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'a@strapi.io']);

        $updated = self::strapi()->service('admin::user')->updateById($user['id'], ['password' => 'Password456']);

        self::assertTrue(password_verify('Password456', (string) $updated['password']));
    }

    public function testUpdateRecordsTheChangedFieldsAndTheLegacyEvent(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'a@strapi.io', 'firstname' => 'Kai']);
        $events = self::listen(['admin-user.update', 'user.update', 'admin-user.password.update']);

        self::strapi()->service('admin::user')->updateById($user['id'], ['firstname' => 'Ada']);
        self::strapi()->service('admin::user')->updateById($user['id'], ['firstname' => 'Ada']);
        self::strapi()->service('admin::user')->updateById($user['id'], ['password' => 'Password456']);

        self::assertCount(1, $events['admin-user.update'], 'nothing recorded when no tracked field changed');
        self::assertSame(['firstname' => ['before' => 'Kai', 'after' => 'Ada']], $events['admin-user.update'][0]['changes']);
        self::assertCount(3, $events['user.update']);
        self::assertCount(1, $events['admin-user.password.update']);
    }

    public function testCannotRemoveTheSuperAdminRoleOfTheLastSuperAdmin(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'a@strapi.io']);

        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('You must have at least one user with super admin role.');
        self::strapi()->service('admin::user')->updateById($user['id'], ['roles' => [self::role('strapi-editor')['id']]]);
    }

    public function testCanRemoveTheSuperAdminRoleWhenAnotherSuperAdminRemains(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'a@strapi.io']);
        BootedAdminApp::createUser(self::strapi(), ['email' => 'b@strapi.io']);

        $updated = self::strapi()->service('admin::user')->updateById($user['id'], ['roles' => [self::role('strapi-editor')['id']]]);

        self::assertSame(['strapi-editor'], array_column($updated['roles'], 'code'));
    }

    public function testCannotDisableTheLastSuperAdmin(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'a@strapi.io']);

        $this->expectException(ValidationError::class);
        self::strapi()->service('admin::user')->updateById($user['id'], ['isActive' => false]);
    }

    public function testDeleteByIdsCannotDeleteTheLastSuperAdmin(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'a@strapi.io']);

        $this->expectException(ValidationError::class);
        self::strapi()->service('admin::user')->deleteByIds([$user['id']]);
    }

    public function testDeleteByIdInvalidatesTheUserSessions(): void
    {
        BootedAdminApp::createUser(self::strapi(), ['email' => 'a@strapi.io']);
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'b@strapi.io']);
        self::strapi()->sessionManager()('admin')->generateRefreshToken((string) $user['id'], 'device-1');

        $deleted = self::strapi()->service('admin::user')->deleteById($user['id']);

        self::assertSame($user['id'], $deleted['id'] ?? null);
        self::assertSame([], self::strapi()->sessionManager()('admin')->listSessions((string) $user['id']));
        self::assertNull(self::strapi()->service('admin::user')->deleteById($user['id']), 'null when the user does not exist');
    }

    public function testDeleteByIdsInvalidatesSessionsForAllDeletedUsers(): void
    {
        BootedAdminApp::createUser(self::strapi(), ['email' => 'keep@strapi.io']);
        $a = BootedAdminApp::createUser(self::strapi(), ['email' => 'a@strapi.io']);
        $b = BootedAdminApp::createUser(self::strapi(), ['email' => 'b@strapi.io']);
        self::strapi()->sessionManager()('admin')->generateRefreshToken((string) $a['id'], 'device-a');
        self::strapi()->sessionManager()('admin')->generateRefreshToken((string) $b['id'], 'device-b');

        $deleted = self::strapi()->service('admin::user')->deleteByIds([$a['id'], $b['id']]);

        self::assertCount(2, $deleted);
        self::assertSame(0, self::strapi()->db()->query('admin::session')->count([]));
    }

    public function testExists(): void
    {
        BootedAdminApp::createUser(self::strapi(), ['email' => 'a@strapi.io']);

        self::assertTrue(self::strapi()->service('admin::user')->exists(['email' => 'a@strapi.io']));
        self::assertFalse(self::strapi()->service('admin::user')->exists(['email' => 'nobody@strapi.io']));
    }

    public function testFindPageWithPagination(): void
    {
        foreach (range(1, 3) as $i) {
            BootedAdminApp::createUser(self::strapi(), ['email' => "u{$i}@strapi.io"]);
        }

        $page = self::strapi()->service('admin::user')->findPage(['page' => 2, 'pageSize' => 2]);

        self::assertSame(['page' => 2, 'pageSize' => 2, 'pageCount' => 2, 'total' => 3], $page['pagination']);
        self::assertCount(1, $page['results']);
        self::assertArrayHasKey('roles', $page['results'][0], 'roles are populated by default');
    }

    public function testFindOneAndFindOneByEmailIsCaseInsensitive(): void
    {
        $user = BootedAdminApp::createUser(self::strapi(), ['email' => 'kai@strapi.io']);

        self::assertSame($user['id'], self::strapi()->service('admin::user')->findOne($user['id'])['id'] ?? null);
        self::assertNull(self::strapi()->service('admin::user')->findOne(999_999));
        self::assertSame($user['id'], self::strapi()->service('admin::user')->findOneByEmail('KAI@Strapi.io')['id'] ?? null);
    }

    public function testFindRegistrationInfo(): void
    {
        self::assertNull(self::strapi()->service('admin::user')->findRegistrationInfo('nope'));

        self::strapi()->service('admin::user')->create(['email' => 'invited@strapi.io', 'firstname' => 'In', 'lastname' => 'Vited', 'registrationToken' => 'reg-token']);

        self::assertSame(['email' => 'invited@strapi.io', 'firstname' => 'In', 'lastname' => 'Vited'], self::strapi()->service('admin::user')->findRegistrationInfo('reg-token'));
    }

    public function testRegisterFailsIfNoMatchingUserIsFound(): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Invalid registration info');
        self::strapi()->service('admin::user')->register(['registrationToken' => 'nope', 'userInfo' => ['firstname' => 'A', 'password' => 'Password123']]);
    }

    public function testRegisterActivatesTheUserResetsTheTokenAndRecordsTheInvitation(): void
    {
        self::strapi()->service('admin::user')->create(['email' => 'invited@strapi.io', 'lastname' => 'Kept', 'registrationToken' => 'reg-token']);
        $events = self::listen(['admin-user.invite.accept']);

        $user = self::strapi()->service('admin::user')->register(['registrationToken' => 'reg-token', 'userInfo' => ['firstname' => 'In', 'password' => 'Password123']]);

        self::assertTrue($user['isActive']);
        self::assertNull($user['registrationToken']);
        self::assertSame('Kept', $user['lastname'], 'an omitted field is left untouched');
        self::assertSame(['userId' => $user['id'], 'email' => 'invited@strapi.io'], $events['admin-user.invite.accept'][0]);
    }

    public function testDisplayWarningIfUsersDontHaveRole(): void
    {
        $logs = BootedAdminApp::recordLogs(self::strapi());
        try {
            BootedAdminApp::createUser(self::strapi(), ['email' => 'a@strapi.io']);
            self::strapi()->service('admin::user')->displayWarningIfUsersDontHaveRole();
            self::assertSame([], $logs->messages('warning'));

            self::strapi()->service('admin::user')->create(['email' => 'b@strapi.io']);
            self::strapi()->service('admin::user')->create(['email' => 'c@strapi.io']);
            self::strapi()->service('admin::user')->displayWarningIfUsersDontHaveRole();
            self::assertSame(["Some users (2) don't have any role."], $logs->messages('warning'));
        } finally {
            self::strapi()->set('logger', \Strapi\Logger\Logger::createLogger(['level' => 'error']));
        }
    }

    public function testResetPasswordByEmail(): void
    {
        try {
            self::strapi()->service('admin::user')->resetPasswordByEmail('nobody@strapi.io', 'Password123');
            self::fail('expected an error');
        } catch (\RuntimeException $e) {
            self::assertSame('User not found for email: nobody@strapi.io', $e->getMessage());
        }

        BootedAdminApp::createUser(self::strapi(), ['email' => 'a@strapi.io']);
        try {
            self::strapi()->service('admin::user')->resetPasswordByEmail('a@strapi.io', 'weak');
            self::fail('expected a ValidationError');
        } catch (ValidationError $e) {
            self::assertStringStartsWith('Invalid password.', $e->getMessage());
        }

        self::strapi()->service('admin::user')->resetPasswordByEmail('a@strapi.io', 'Password456');
        $user = self::strapi()->db()->query('admin::user')->findOne(['where' => ['email' => 'a@strapi.io']]);
        self::assertTrue(password_verify('Password456', (string) $user['password']));
    }

    public function testGetLanguagesInUse(): void
    {
        BootedAdminApp::createUser(self::strapi(), ['email' => 'a@strapi.io', 'preferedLanguage' => 'fr']);
        BootedAdminApp::createUser(self::strapi(), ['email' => 'b@strapi.io']);

        self::assertSame(['fr', 'en'], self::strapi()->service('admin::user')->getLanguagesInUse());
    }
}
