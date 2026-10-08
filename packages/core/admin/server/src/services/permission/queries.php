<?php

declare(strict_types=1);

namespace Strapi\Admin\Services\Permission;

use Strapi\Admin\Domain\Permission\Permission as PermissionDomain;
use Strapi\Core\Strapi;

/**
 * Port of server/src/services/permission/queries.ts. Upstream exports functions reading the global
 * `strapi`; here they are methods of a class holding the instance.
 */
final class Queries
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function toPermissions(array $rows): array
    {
        return array_values(array_map(static fn (array $row): array => PermissionDomain::create($row), $rows));
    }

    /**
     * Delete permissions of roles in database.
     *
     * @param list<int|string> $rolesIds ids of roles
     */
    public function deleteByRolesIds(array $rolesIds): void
    {
        $permissionsToDelete = $this->strapi->db()->query('admin::permission')->findMany([
            'select' => ['id'],
            'where' => [
                'role' => ['id' => array_values($rolesIds)],
            ],
        ]);

        if (count($permissionsToDelete) > 0) {
            $this->deleteByIds(array_values(array_map(static fn (array $p): int|string => $p['id'], $permissionsToDelete)));
        }
    }

    /**
     * Delete permissions.
     *
     * @param list<int|string> $ids ids of permissions
     */
    public function deleteByIds(array $ids): void
    {
        $result = [];
        foreach ($ids as $id) {
            $result[] = $this->strapi->db()->query('admin::permission')->delete(['where' => ['id' => $id]]);
        }

        $this->strapi->eventHub()->emit('permission.delete', ['permissions' => $result]);
    }

    /**
     * Create many permissions.
     *
     * @param list<array<string, mixed>> $permissions
     * @return list<array<string, mixed>>
     */
    public function createMany(array $permissions): array
    {
        $createdPermissions = [];
        foreach ($permissions as $permission) {
            $createdPermissions[] = $this->strapi->db()->query('admin::permission')->create(['data' => $permission]);
        }

        $permissionsToReturn = self::toPermissions($createdPermissions);
        $this->strapi->eventHub()->emit('permission.create', ['permissions' => $permissionsToReturn]);

        return $permissionsToReturn;
    }

    /**
     * Update a permission.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>|null
     */
    public function update(array $params, array $attributes): ?array
    {
        $updatedPermission = $this->strapi->db()->query('admin::permission')->update(['where' => $params, 'data' => $attributes]);

        $permissionToReturn = $updatedPermission === null ? null : PermissionDomain::create($updatedPermission);
        $this->strapi->eventHub()->emit('permission.update', ['permissions' => $permissionToReturn]);

        return $permissionToReturn;
    }

    /**
     * Find assigned permissions in the database.
     *
     * @param array<string, mixed> $params query params to find the permissions
     * @return list<array<string, mixed>>
     */
    public function findMany(array $params = []): array
    {
        $rawPermissions = $this->strapi->db()->query('admin::permission')->findMany($params);

        return self::toPermissions(array_values($rawPermissions));
    }

    /**
     * Find all permissions for a user.
     *
     * @param array<string, mixed> $user
     * @return list<array<string, mixed>>
     */
    public function findUserPermissions(array $user): array
    {
        return $this->findMany(['where' => ['role' => ['users' => ['id' => $user['id'] ?? null]]]]);
    }

    /**
     * @param list<array<string, mixed>> $permissions
     * @return list<array<string, mixed>>
     */
    private function filterPermissionsToRemove(array $permissions): array
    {
        /** @var \Strapi\Admin\Services\Permission $permissionService */
        $permissionService = $this->strapi->service('admin::permission');
        $actionProvider = $permissionService->actionProvider;

        $permissionsToRemove = [];

        foreach ($permissions as $permission) {
            $actionId = (string) ($permission['action'] ?? '');
            $action = $actionProvider->get($actionId) ?? [];
            $subjects = $action['subjects'] ?? null;
            $applyToProperties = $action['options']['applyToProperties'] ?? null;
            $subject = $permission['subject'] ?? null;

            $invalidProperties = [];
            foreach (is_array($applyToProperties) ? $applyToProperties : [] as $property) {
                $applies = $actionProvider->appliesToProperty((string) $property, $actionId, is_string($subject) ? $subject : null);

                $invalidProperties[] = $applies && PermissionDomain::getProperty((string) $property, $permission) === null;
            }

            $isRegisteredAction = $actionProvider->has($actionId);
            $hasInvalidProperties = is_array($applyToProperties) && !in_array(false, $invalidProperties, true);
            $isInvalidSubject = is_array($subjects) && !in_array($subject, $subjects, true);
            // On an api token permission, nil properties mean "everything", not "invalid"
            $hasApiToken = ($permission['apiToken'] ?? null) !== null;

            // If the permission has an invalid action, an invalid subject or invalid properties, then add it to the toBeRemoved collection
            if (!$isRegisteredAction || $isInvalidSubject || ($hasInvalidProperties && !$hasApiToken)) {
                $permissionsToRemove[] = $permission;
            }
        }

        return $permissionsToRemove;
    }

    /**
     * Removes permissions in database that don't exist anymore.
     */
    public function cleanPermissionsInDatabase(): void
    {
        $pageSize = 200;

        /** @var \Strapi\Admin\Services\ContentType $contentTypeService */
        $contentTypeService = $this->strapi->service('admin::content-type');

        $total = $this->strapi->db()->query('admin::permission')->count();
        $pageCount = (int) ceil($total / $pageSize);

        for ($page = 0; $page < $pageCount; $page += 1) {
            // 1. Find invalid permissions and collect their ID to delete them later
            $results = $this->strapi->db()->query('admin::permission')->findMany([
                'limit' => $pageSize,
                'offset' => $page * $pageSize,
                'populate' => ['role', 'apiToken'],
            ]);

            $permissions = self::toPermissions(array_values($results));
            $permissionsToRemove = $this->filterPermissionsToRemove($permissions);

            // Also remove orphaned permissions (no role AND no apiToken)
            $orphanedPermissions = array_values(array_filter(
                $permissions,
                static fn (array $permission): bool => ($permission['role'] ?? null) === null && ($permission['apiToken'] ?? null) === null,
            ));

            $permissionsIdToRemove = array_values(array_unique(array_map(
                static fn (array $p): mixed => $p['id'] ?? null,
                [...$permissionsToRemove, ...$orphanedPermissions],
            ), SORT_REGULAR));

            // 2. Clean permissions' fields (add required ones, remove the non-existing ones)
            $remainingPermissions = array_values(array_filter(
                $permissions,
                static fn (array $permission): bool => !in_array($permission['id'] ?? null, $permissionsIdToRemove, true),
            ));

            $permissionsWithCleanFields = $contentTypeService->cleanPermissionFields($remainingPermissions);

            // Update only the ones that need to be updated
            $permissionsNeedingToBeUpdated = [];
            foreach ($permissionsWithCleanFields as $a) {
                $same = false;
                foreach ($remainingPermissions as $b) {
                    if (($a['id'] ?? null) === ($b['id'] ?? null) && self::xorEmpty($a['properties']['fields'] ?? null, $b['properties']['fields'] ?? null)) {
                        $same = true;
                        break;
                    }
                }
                if (!$same) {
                    $permissionsNeedingToBeUpdated[] = $a;
                }
            }

            // Execute all the queries, update the database
            $this->deleteByIds($permissionsIdToRemove);
            foreach ($permissionsNeedingToBeUpdated as $permission) {
                // upstream passes the whole permission (relations included, unchanged); only its own columns are written here
                $this->update(['id' => $permission['id']], array_diff_key($permission, ['id' => true, 'role' => true, 'apiToken' => true]));
            }
        }
    }

    /** lodash `xor(a, b).length === 0` (non-arrays count as empty). */
    private static function xorEmpty(mixed $a, mixed $b): bool
    {
        $a = is_array($a) ? array_values(array_unique($a, SORT_REGULAR)) : [];
        $b = is_array($b) ? array_values(array_unique($b, SORT_REGULAR)) : [];

        foreach ($a as $value) {
            if (!in_array($value, $b, true)) {
                return false;
            }
        }
        foreach ($b as $value) {
            if (!in_array($value, $a, true)) {
                return false;
            }
        }

        return true;
    }
}
