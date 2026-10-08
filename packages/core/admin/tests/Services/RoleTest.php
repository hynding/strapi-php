<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Services;

use Strapi\Admin\Services\Permission;
use Strapi\Admin\Services\Role;
use Strapi\Core\Tests\BootedAppTestCase;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/services/__tests__/role.test.ts. Upstream mocks `strapi.db`; `Strapi` and the
 * database are final here, so the cases run against `examples/getstarted` booted on in-memory SQLite
 * (the admin bootstrap has already created the default roles and super admin permissions).
 */
final class RoleTest extends BootedAppTestCase
{
    private static function role(): Role
    {
        /** @var Role $service */
        $service = self::strapi()->service('admin::role');

        return $service;
    }

    private static function permission(): Permission
    {
        /** @var Permission $service */
        $service = self::strapi()->service('admin::permission');

        return $service;
    }

    public function testCreatesARole(): void
    {
        $role = self::role()->create(['name' => 'create test', 'description' => 'd']);

        self::assertSame('create test', $role['name']);
        self::assertStringStartsWith('create-test-', $role['code']);
        self::assertSame($role['id'], self::role()->findOne(['id' => $role['id']])['id'] ?? null);
    }

    public function testCreateKeepsAGivenCode(): void
    {
        $role = self::role()->create(['name' => 'with code', 'code' => 'my-code']);

        self::assertSame('my-code', $role['code']);
    }

    public function testCannotCreateTwoRolesWithTheSameName(): void
    {
        self::role()->create(['name' => 'dup']);

        $this->expectException(ApplicationError::class);
        $this->expectExceptionMessage('The name must be unique and a role with name `dup` already exists.');

        self::role()->create(['name' => 'dup']);
    }

    public function testFindsARoleWithUsersCount(): void
    {
        $superAdmin = self::role()->getSuperAdminWithUsersCount();

        self::assertNotNull($superAdmin);
        self::assertSame('strapi-super-admin', $superAdmin['code']);
        self::assertSame(0, $superAdmin['usersCount']);
    }

    public function testFindsRoles(): void
    {
        $codes = array_column(self::role()->find(['code' => ['$in' => ['strapi-editor', 'strapi-author']]]), 'code');
        sort($codes);

        self::assertSame(['strapi-author', 'strapi-editor'], $codes);
    }

    public function testFindsAllRolesWithUsersCount(): void
    {
        $roles = self::role()->findAllWithUsersCount([]);

        self::assertGreaterThanOrEqual(3, count($roles));
        foreach ($roles as $role) {
            self::assertArrayHasKey('usersCount', $role);
        }
    }

    public function testUpdatesARoleButNotItsCode(): void
    {
        $role = self::role()->create(['name' => 'to update']);

        $updated = self::role()->update(['id' => $role['id']], ['name' => 'updated', 'description' => 'new', 'code' => 'ignored']);

        self::assertNotNull($updated);
        self::assertSame('updated', $updated['name']);
        self::assertSame('new', $updated['description']);
        self::assertSame($role['code'], $updated['code']);
    }

    public function testCannotRenameARoleToAnExistingName(): void
    {
        $role = self::role()->create(['name' => 'rename me']);

        $this->expectException(ApplicationError::class);
        $this->expectExceptionMessage('The name must be unique and a role with name `Editor` already exists.');

        self::role()->update(['id' => $role['id']], ['name' => 'Editor']);
    }

    public function testCountAndExists(): void
    {
        self::assertGreaterThanOrEqual(3, self::role()->count());
        self::assertSame(1, self::role()->count(['where' => ['code' => 'strapi-editor']]));
        self::assertTrue(self::role()->exists(['code' => 'strapi-author']));
        self::assertFalse(self::role()->exists(['code' => 'nope']));
    }

    public function testGetUsersCount(): void
    {
        self::assertSame(0, self::role()->getUsersCount(self::role()->getSuperAdmin()['id'] ?? 0));
    }

    public function testDeletesRolesAndTheirPermissions(): void
    {
        $a = self::role()->create(['name' => 'delete a']);
        $b = self::role()->create(['name' => 'delete b']);
        self::role()->addPermissions($a['id'], [['action' => 'admin::webhooks.read']]);

        $deleted = self::role()->deleteByIds([$a['id'], $b['id']]);

        self::assertSame([$a['id'], $b['id']], array_column($deleted, 'id'));
        self::assertNull(self::role()->findOne(['id' => $a['id']]));
        self::assertSame([], self::permission()->findMany(['where' => ['role' => ['id' => $a['id']]]]));
    }

    public function testCannotDeleteTheSuperAdminRole(): void
    {
        $this->expectException(ApplicationError::class);
        $this->expectExceptionMessage('You cannot delete the super admin role');

        self::role()->deleteByIds([(string) (self::role()->getSuperAdmin()['id'] ?? 0)]);
    }

    public function testCannotDeleteARoleAttachedToSomeUser(): void
    {
        $role = self::role()->create(['name' => 'with user']);
        self::strapi()->db()->query('admin::user')->create(['data' => ['email' => 'role-test@strapi.io', 'firstname' => 'a', 'lastname' => 'b', 'roles' => [$role['id']]]]);

        $this->expectException(ApplicationError::class);
        $this->expectExceptionMessage('Some roles are still assigned to some users');

        self::role()->deleteByIds([$role['id']]);
    }

    public function testDoesNotCreateRolesIfOneAlreadyExists(): void
    {
        $before = self::role()->count();

        self::role()->createRolesIfNoneExist();

        self::assertSame($before, self::role()->count());
    }

    public function testDefaultPluginPermissions(): void
    {
        $author = self::role()->getDefaultPluginPermissions(['isAuthor' => true]);

        self::assertSame(['plugin::upload.read', 'plugin::upload.configure-view', 'plugin::upload.assets.create', 'plugin::upload.assets.update', 'plugin::upload.assets.download', 'plugin::upload.assets.copy-link'], array_column($author, 'action'));
        self::assertSame(['admin::is-creator'], $author[0]['conditions']);
        self::assertSame([], $author[1]['conditions']);
        self::assertSame([], self::role()->getDefaultPluginPermissions()[0]['conditions']);
        self::assertSame(['actionParameters' => [], 'conditions' => [], 'properties' => [], 'subject' => null, 'action' => 'plugin::upload.configure-view'], $author[1]);
    }

    public function testSuperAdminHasEveryRegisteredAction(): void
    {
        self::role()->resetSuperAdminPermissions();

        $superAdmin = self::role()->getSuperAdmin();
        $actions = array_column(self::permission()->findMany(['where' => ['role' => ['id' => $superAdmin['id'] ?? 0]]]), 'action');
        $expected = array_column(array_filter(self::permission()->actionProvider->values(), static fn (array $a): bool => $a['section'] !== 'internal'), 'actionId');
        // an action with subjects (content-manager's) gives one permission per subject
        $actions = array_values(array_unique($actions));
        sort($actions);
        sort($expected);

        self::assertSame($expected, $actions);
    }

    public function testAssignPermissionsReturnsOnlyCreatedPermissions(): void
    {
        $role = self::role()->create(['name' => 'assign']);

        $first = self::role()->assignPermissions($role['id'], [
            ['action' => 'admin::webhooks.read'],
            ['action' => 'admin::roles.read', 'conditions' => ['admin::is-creator']],
        ]);
        self::assertCount(2, $first);

        // unchanged permission kept, changed one replaced: only the new one is returned
        $second = self::role()->assignPermissions($role['id'], [
            ['action' => 'admin::webhooks.read'],
            ['action' => 'admin::roles.read', 'conditions' => ['admin::is-creator', 'unknown-condition']],
        ]);
        self::assertCount(1, $second);
        self::assertSame('admin::roles.read', $second[0]['action']);
        self::assertSame(['admin::is-creator'], $second[0]['conditions']);

        self::assertCount(2, self::permission()->findMany(['where' => ['role' => ['id' => $role['id']]]]));

        // deleting previous permissions
        self::assertSame([], self::role()->assignPermissions($role['id'], []));
        self::assertSame([], self::permission()->findMany(['where' => ['role' => ['id' => $role['id']]]]));
    }

    public function testAssignPermissionsRejectsUnknownActions(): void
    {
        $role = self::role()->create(['name' => 'assign unknown']);

        $this->expectExceptionMessage('[0] is not an existing permission action');

        self::role()->assignPermissions($role['id'], [['action' => 'foo::bar']]);
    }

    public function testAddPermissionsAddsTheRoleAndSanitizesConditions(): void
    {
        $role = self::role()->create(['name' => 'add permissions']);

        $created = self::role()->addPermissions($role['id'], [['action' => 'admin::webhooks.read', 'conditions' => ['admin::is-creator', 'nope']]]);

        self::assertSame(['admin::is-creator'], $created[0]['conditions']);
        self::assertCount(1, self::permission()->findMany(['where' => ['role' => ['id' => $role['id']]]]));
    }

    public function testHasSuperAdminRole(): void
    {
        self::assertTrue(self::role()->hasSuperAdminRole(['roles' => [['code' => 'strapi-super-admin']]]));
        self::assertFalse(self::role()->hasSuperAdminRole(['roles' => [['code' => 'strapi-editor']]]));
        self::assertFalse(self::role()->hasSuperAdminRole([]));
    }

    public function testSanitizeRoleRemovesUsersAndPermissions(): void
    {
        self::assertSame(['id' => 1, 'name' => 'r'], self::role()->sanitizeRole(['id' => 1, 'name' => 'r', 'users' => [], 'permissions' => []]));
    }
}
