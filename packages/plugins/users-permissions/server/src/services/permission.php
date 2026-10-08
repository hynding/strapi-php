<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Services;

use Strapi\Core\Strapi;

/** Port of server/src/services/permission.js. */
final class Permission
{
    private const PUBLIC_ROLE_FILTER = ['role' => ['type' => 'public']];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Find permissions associated to a specific role ID
     *
     * @return list<array<string, mixed>>
     */
    public function findRolePermissions(int|string $roleID): array
    {
        $permissions = $this->strapi->db()->query('plugin::users-permissions.role')->load(['id' => $roleID], 'permissions');

        return is_array($permissions) ? array_values($permissions) : [];
    }

    /**
     * Find permissions for the public role
     *
     * @return list<array<string, mixed>>
     */
    public function findPublicPermissions(): array
    {
        return array_values($this->strapi->db()->query('plugin::users-permissions.permission')->findMany([
            'where' => self::PUBLIC_ROLE_FILTER,
        ]));
    }

    /**
     * Transform a Users-Permissions' action into a content API one
     *
     * @param array<string, mixed> $permission
     * @return array{action: string}
     */
    public function toContentAPIPermission(array $permission): array
    {
        $action = $permission['action'] ?? null;

        return ['action' => is_string($action) ? $action : ''];
    }
}
