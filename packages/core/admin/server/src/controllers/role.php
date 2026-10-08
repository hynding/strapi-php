<?php

declare(strict_types=1);

namespace Strapi\Admin\Controllers;

use Strapi\Admin\Services\Permission as PermissionService;
use Strapi\Admin\Services\Role as RoleService;
use Strapi\Admin\Validation\Permission as PermissionValidation;
use Strapi\Admin\Validation\Role as RoleValidation;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Abilities\Ability;
use Strapi\Utils\Errors\ApplicationError;

/** Port of server/src/controllers/role.ts. */
final class Role
{
    private const string SUPER_ADMIN_CODE = 'strapi-super-admin';

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Typed as `object` natively so that unit tests can register stubs (upstream tests mock services).
     *
     * @return RoleService
     */
    private function roleService(): object
    {
        /** @var RoleService $service */
        $service = $this->strapi->service('admin::role');

        return $service;
    }

    /**
     * Typed as `object` natively so that unit tests can register stubs (upstream tests mock services).
     *
     * @return PermissionService
     */
    private function permissionService(): object
    {
        /** @var PermissionService $service */
        $service = $this->strapi->service('admin::permission');

        return $service;
    }

    /**
     * JSON encoding: PHP arrays have no "empty object", so empty `properties` / `actionParameters`
     * become `{}` as upstream responds.
     *
     * @param array<string, mixed> $permission
     * @return array<string, mixed>
     */
    public static function toJsonPermission(array $permission): array
    {
        foreach (['properties', 'actionParameters'] as $key) {
            if (array_key_exists($key, $permission) && $permission[$key] === []) {
                $permission[$key] = new \stdClass();
            }
        }

        return $permission;
    }

    /**
     * @param list<array<string, mixed>> $permissions
     * @return list<array<string, mixed>>
     */
    private function sanitizePermissions(array $permissions): array
    {
        $permissionService = $this->permissionService();

        return array_values(array_map(
            static fn (array $permission): array => self::toJsonPermission($permissionService->sanitizePermission($permission)),
            $permissions,
        ));
    }

    /**
     * Create a new role.
     */
    public function create(Context $ctx): mixed
    {
        $body = $ctx->requestBody();
        RoleValidation::validateRoleCreateInput($body);

        $roleService = $this->roleService();

        $role = $roleService->create(is_array($body) ? $body : []);
        $sanitizedRole = $roleService->sanitizeRole($role);

        $ctx->created(['data' => $sanitizedRole]);

        return null;
    }

    /**
     * Returns one role by id.
     */
    public function findOne(Context $ctx): mixed
    {
        $id = $ctx->param('id');
        $role = $this->roleService()->findOneWithUsersCount(['id' => $id]);

        if ($role === null) {
            $ctx->notFound('role.notFound');

            return null;
        }

        $ctx->setBody(['data' => $role]);

        return null;
    }

    /**
     * Returns every roles.
     */
    public function findAll(Context $ctx): mixed
    {
        $query = $ctx->query();

        $ability = $ctx->state()->get('userAbility');
        if (!$ability instanceof Ability) {
            throw new \RuntimeException('Missing user ability');
        }

        $permissionsManager = $this->permissionService()->createPermissionsManager([
            'ability' => $ability,
            'model' => 'admin::role',
        ]);

        $permissionsManager->validateQuery($query);
        $sanitizedQuery = $permissionsManager->sanitizeQuery($query);

        $roles = $this->roleService()->findAllWithUsersCount(is_array($sanitizedQuery) ? $sanitizedQuery : []);

        $ctx->setBody(['data' => $roles]);

        return null;
    }

    /**
     * Updates a role by id.
     */
    public function update(Context $ctx): mixed
    {
        $id = $ctx->param('id');
        $body = $ctx->requestBody();

        $roleService = $this->roleService();

        RoleValidation::validateRoleUpdateInput($body);

        $role = $roleService->findOne(['id' => $id]);

        if ($role === null) {
            $ctx->notFound('role.notFound');

            return null;
        }

        if (($role['code'] ?? null) === self::SUPER_ADMIN_CODE) {
            throw new ApplicationError("Super admin can't be edited.");
        }

        $updatedRole = $roleService->update(['id' => $id], is_array($body) ? $body : []);
        $sanitizedRole = $roleService->sanitizeRole($updatedRole);

        $ctx->setBody(['data' => $sanitizedRole]);

        return null;
    }

    /**
     * Returns the permissions assigned to a role.
     */
    public function getPermissions(Context $ctx): mixed
    {
        $id = $ctx->param('id');

        $role = $this->roleService()->findOne(['id' => $id]);

        if ($role === null) {
            $ctx->notFound('role.notFound');

            return null;
        }

        $permissions = $this->permissionService()->findMany(['where' => ['role' => ['id' => $role['id']]]]);

        $ctx->setBody(['data' => $this->sanitizePermissions($permissions)]);

        return null;
    }

    /**
     * Updates the permissions assigned to a role.
     */
    public function updatePermissions(Context $ctx): mixed
    {
        $id = $ctx->param('id');
        $input = $ctx->requestBody();

        $roleService = $this->roleService();

        $role = $roleService->findOne(['id' => $id]);

        if ($role === null) {
            $ctx->notFound('role.notFound');

            return null;
        }

        if (($role['code'] ?? null) === self::SUPER_ADMIN_CODE) {
            throw new ApplicationError("Super admin permissions can't be edited.");
        }

        PermissionValidation::validatedUpdatePermissionsInput($input);

        $inputPermissions = is_array($input) && is_array($input['permissions'] ?? null) ? array_values($input['permissions']) : [];

        /** @var list<array<string, mixed>> $normalizedPermissions */
        $normalizedPermissions = $roleService->hooks['willValidateUpdatePermissions']->call($inputPermissions);

        $permissions = $roleService->assignPermissions($role['id'], $normalizedPermissions);

        $ctx->setBody(['data' => $this->sanitizePermissions($permissions)]);

        return null;
    }

    /**
     * Delete a role.
     */
    public function deleteOne(Context $ctx): mixed
    {
        $id = $ctx->param('id');

        RoleValidation::validateRoleDeleteInput($id);

        $roleService = $this->roleService();

        $roles = $roleService->deleteByIds([(string) $id]);

        $sanitizedRole = $roles === [] ? null : $roleService->sanitizeRole($roles[0]);

        $ctx->deleted(['data' => $sanitizedRole]);

        return null;
    }

    /**
     * Delete several roles.
     */
    public function deleteMany(Context $ctx): mixed
    {
        $body = $ctx->requestBody();

        RoleValidation::validateRolesDeleteInput($body);

        $roleService = $this->roleService();

        $ids = is_array($body) && is_array($body['ids'] ?? null) ? array_values($body['ids']) : [];
        $roles = $roleService->deleteByIds($ids);
        $sanitizedRoles = array_map($roleService->sanitizeRole(...), $roles);

        $ctx->deleted(['data' => $sanitizedRoles]);

        return null;
    }
}
