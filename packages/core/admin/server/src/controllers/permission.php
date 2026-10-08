<?php

declare(strict_types=1);

namespace Strapi\Admin\Controllers;

use Strapi\Admin\Controllers\Formatters\Conditions;
use Strapi\Admin\Services\Permission as PermissionService;
use Strapi\Admin\Validation\Permission as PermissionValidation;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Abilities\Ability;

/** Port of server/src/controllers/permission.ts. */
final class Permission
{
    public function __construct(private readonly Strapi $strapi)
    {
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
     * Check each permissions from `request.body.permissions` and returns an array of booleans.
     */
    public function check(Context $ctx): mixed
    {
        $input = $ctx->requestBody();
        $userAbility = $ctx->state()->get('userAbility');

        PermissionValidation::validateCheckPermissionsInput($input);

        $engine = $this->permissionService()->engine;

        $permissions = is_array($input) && is_array($input['permissions'] ?? null) ? array_values($input['permissions']) : [];

        $ctx->setBody([
            'data' => $userAbility instanceof Ability ? $engine->checkMany($userAbility, $permissions) : array_map(static fn (): bool => false, $permissions),
        ]);

        return null;
    }

    /**
     * Returns every permissions, in nested format.
     */
    public function getAll(Context $ctx): mixed
    {
        $permissionService = $this->permissionService();

        $actions = $permissionService->actionProvider->values();
        $conditions = $permissionService->conditionProvider->values();
        $sections = $permissionService->sectionsBuilder->build($actions);

        $ctx->setBody([
            'data' => [
                'conditions' => Conditions::formatConditions($conditions),
                'sections' => $sections,
            ],
        ]);

        return null;
    }
}
