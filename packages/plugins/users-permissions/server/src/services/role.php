<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Services;

use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Utils\Utils;
use Strapi\Utils\Errors\NotFoundError;
use Strapi\Utils\Primitives\Strings;

/** Port of server/src/services/role.js. */
final class Role
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Enabled actions of a `{ [typeName]: { controllers: { [controllerName]: { [actionName]: { enabled } } } } }` tree.
     *
     * @return list<string>
     */
    private static function enabledActions(mixed $permissions): array
    {
        $actions = [];
        foreach (is_array($permissions) ? $permissions : [] as $typeName => $type) {
            $controllers = is_array($type) ? ($type['controllers'] ?? []) : [];
            foreach (is_array($controllers) ? $controllers : [] as $controllerName => $controller) {
                foreach (is_array($controller) ? $controller : [] as $actionName => $action) {
                    $enabled = is_array($action) ? ($action['enabled'] ?? null) : null;
                    if ($enabled !== null && $enabled !== false && $enabled !== 0 && $enabled !== '') {
                        $actions[] = "{$typeName}.{$controllerName}.{$actionName}";
                    }
                }
            }
        }

        return $actions;
    }

    /** @param array<string, mixed> $params */
    public function createRole(array $params): void
    {
        if (!isset($params['type']) || $params['type'] === '' || $params['type'] === false) {
            // _.snakeCase(_.deburr(_.toLower(params.name)))
            $params['type'] = Strings::snakeCase(Strings::transliterate(mb_strtolower(is_scalar($params['name'] ?? null) ? (string) $params['name'] : '')));
        }

        $data = $params;
        unset($data['users'], $data['permissions']);
        $role = $this->strapi->db()->query('plugin::users-permissions.role')->create(['data' => $data]);

        foreach (self::enabledActions($params['permissions'] ?? null) as $actionID) {
            $this->strapi->db()->query('plugin::users-permissions.permission')->create(['data' => ['action' => $actionID, 'role' => $role['id']]]);
        }
    }

    /** @return array<string, mixed> */
    public function findOne(int|string $roleID): array
    {
        $role = $this->strapi->db()->query('plugin::users-permissions.role')->findOne(['where' => ['id' => $roleID], 'populate' => ['permissions']]);

        if ($role === null) {
            throw new NotFoundError('Role not found');
        }

        $allActions = Utils::getService($this->strapi, 'users-permissions')->getActions();

        // Group by `type`.
        foreach ($role['permissions'] ?? [] as $permission) {
            $parts = explode('.', (string) ($permission['action'] ?? ''));
            [$type, $controller, $action] = [$parts[0], $parts[1] ?? 'undefined', $parts[2] ?? 'undefined'];

            $allActions[$type] ??= [];
            $allActions[$type]['controllers'] ??= [];
            $allActions[$type]['controllers'][$controller] ??= [];
            $allActions[$type]['controllers'][$controller][$action] = [
                'enabled' => true,
                'policy' => '',
            ];
        }

        return [
            ...$role,
            'permissions' => $allActions,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function find(): array
    {
        $roles = $this->strapi->db()->query('plugin::users-permissions.role')->findMany(['sort' => ['name']]);

        foreach ($roles as $i => $role) {
            $roles[$i]['nb_users'] = $this->strapi->db()->query('plugin::users-permissions.user')->count(['where' => ['role' => ['id' => $role['id']]]]);
        }

        return array_values($roles);
    }

    /** @param array<string, mixed> $data */
    public function updateRole(int|string $roleID, array $data): void
    {
        $role = $this->strapi->db()->query('plugin::users-permissions.role')->findOne(['where' => ['id' => $roleID], 'populate' => ['permissions']]);

        if ($role === null) {
            throw new NotFoundError('Role not found');
        }

        $this->strapi->db()->query('plugin::users-permissions.role')->update([
            'where' => ['id' => $roleID],
            'data' => array_intersect_key($data, ['name' => true, 'description' => true]),
        ]);

        $newActions = self::enabledActions($data['permissions'] ?? null);

        $rolePermissions = is_array($role['permissions'] ?? null) ? $role['permissions'] : [];
        $oldActions = array_map(static fn (array $permission): mixed => $permission['action'] ?? null, $rolePermissions);

        $toDelete = array_filter($rolePermissions, static fn (array $permission): bool => !in_array($permission['action'] ?? null, $newActions, true));

        $toCreate = array_map(
            static fn (string $action): array => ['action' => $action, 'role' => $role['id']],
            array_values(array_filter($newActions, static fn (string $action): bool => !in_array($action, $oldActions, true))),
        );

        foreach ($toDelete as $permission) {
            $this->strapi->db()->query('plugin::users-permissions.permission')->delete(['where' => ['id' => $permission['id']]]);
        }

        foreach ($toCreate as $permissionInfo) {
            $this->strapi->db()->query('plugin::users-permissions.permission')->create(['data' => $permissionInfo]);
        }
    }

    public function deleteRole(int|string $roleID, int|string $publicRoleID): void
    {
        $role = $this->strapi->db()->query('plugin::users-permissions.role')->findOne(['where' => ['id' => $roleID], 'populate' => ['users', 'permissions']]);

        if ($role === null) {
            throw new NotFoundError('Role not found');
        }

        // Move users to guest role.
        foreach ($role['users'] ?? [] as $user) {
            $this->strapi->db()->query('plugin::users-permissions.user')->update([
                'where' => ['id' => $user['id']],
                'data' => ['role' => $publicRoleID],
            ]);
        }

        // Remove permissions related to this role.
        // TODO: use delete many
        foreach ($role['permissions'] ?? [] as $permission) {
            $this->strapi->db()->query('plugin::users-permissions.permission')->delete([
                'where' => ['id' => $permission['id']],
            ]);
        }

        // Delete the role.
        $this->strapi->db()->query('plugin::users-permissions.role')->delete(['where' => ['id' => $roleID]]);
    }
}
