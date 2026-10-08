<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Services;

use Strapi\Admin\Services\Permission;
use Strapi\Admin\Services\Role;
use Strapi\Core\Tests\BootedAppTestCase;

/**
 * Port of server/src/services/__tests__/permission.test.ts, against `examples/getstarted` booted on
 * in-memory SQLite (upstream mocks `strapi.db`).
 */
final class PermissionTest extends BootedAppTestCase
{
    private static function permission(): Permission
    {
        /** @var Permission $service */
        $service = self::strapi()->service('admin::permission');

        return $service;
    }

    private static function role(): Role
    {
        /** @var Role $service */
        $service = self::strapi()->service('admin::role');

        return $service;
    }

    public function testFindManyReturnsDomainPermissions(): void
    {
        $role = self::role()->create(['name' => 'find many']);
        self::role()->addPermissions($role['id'], [['action' => 'admin::webhooks.read']]);

        $permissions = self::permission()->findMany(['where' => ['role' => ['id' => $role['id']]]]);

        self::assertCount(1, $permissions);
        self::assertSame('admin::webhooks.read', $permissions[0]['action']);
        self::assertSame([], $permissions[0]['conditions']);
        self::assertArrayHasKey('actionParameters', $permissions[0]);
    }

    public function testFindUserPermissions(): void
    {
        $role = self::role()->create(['name' => 'user permissions']);
        self::role()->addPermissions($role['id'], [['action' => 'admin::roles.read']]);
        $user = self::strapi()->db()->query('admin::user')->create(['data' => ['email' => 'perm-user@strapi.io', 'firstname' => 'a', 'lastname' => 'b', 'roles' => [$role['id']]]]);

        self::assertSame(['admin::roles.read'], array_column(self::permission()->findUserPermissions($user), 'action'));
    }

    public function testSanitizePermissionRemovesUnwantedProperties(): void
    {
        $permission = ['id' => 1, 'action' => 'read', 'actionParameters' => [], 'subject' => 'article', 'properties' => ['fields' => ['*']], 'conditions' => [], 'foo' => 'bar', 'role' => 1];

        self::assertSame(['id' => 1, 'action' => 'read', 'actionParameters' => [], 'subject' => 'article', 'properties' => ['fields' => ['*']], 'conditions' => []], self::permission()->sanitizePermission($permission));
    }

    public function testCleanPermissionsInDatabase(): void
    {
        $db = self::strapi()->db();
        // explicit JSON columns: the database layer cannot bind a default `{}` (stdClass) yet
        $base = ['actionParameters' => [], 'properties' => [], 'conditions' => []];
        $role = self::role()->create(['name' => 'clean']);
        $token = $db->query('admin::api-token')->create(['data' => ['name' => 'clean token', 'type' => 'custom', 'accessKey' => 'k', 'kind' => 'content-api']]);

        $keep = $db->query('admin::permission')->create(['data' => [...$base, 'action' => 'admin::webhooks.read', 'role' => $role['id']]]);
        $unknownAction = $db->query('admin::permission')->create(['data' => [...$base, 'action' => 'action-2', 'role' => $role['id']]]);
        $orphan = $db->query('admin::permission')->create(['data' => [...$base, 'action' => 'admin::webhooks.read']]);
        $tokenOnly = $db->query('admin::permission')->create(['data' => [...$base, 'action' => 'admin::webhooks.read', 'apiToken' => $token['id']]]);

        self::permission()->cleanPermissionsInDatabase();

        $ids = array_column($db->query('admin::permission')->findMany(['select' => ['id']]), 'id');
        self::assertContains($keep['id'], $ids);
        self::assertContains($tokenOnly['id'], $ids);
        self::assertNotContains($unknownAction['id'], $ids);
        self::assertNotContains($orphan['id'], $ids);
    }
}
