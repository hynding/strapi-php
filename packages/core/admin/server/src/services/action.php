<?php

declare(strict_types=1);

namespace Strapi\Admin\Services;

use Strapi\Core\Strapi;
use Strapi\Utils\Errors\NotFoundError;

/** Port of server/src/services/action.ts. */
final class Action
{
    private const string AUTHOR_CODE = 'strapi-author';

    private const string PUBLISH_ACTION = 'plugin::content-manager.explorer.publish';

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Returns actions available for a role.
     *
     * @return list<array<string, mixed>>
     */
    public function getAllowedActionsForRole(int|string|null $roleId = null): array
    {
        /** @var Permission $permissionService */
        $permissionService = $this->strapi->service('admin::permission');
        $actionProvider = $permissionService->actionProvider;

        if ($roleId !== null) {
            /** @var Role $roleService */
            $roleService = $this->strapi->service('admin::role');
            $role = $roleService->findOne(['id' => $roleId]);

            if ($role === null) {
                throw new NotFoundError('role.notFound');
            }

            if (($role['code'] ?? null) === self::AUTHOR_CODE) {
                return array_values(array_filter(
                    $actionProvider->values(),
                    static fn (array $action): bool => ($action['actionId'] ?? null) !== self::PUBLISH_ACTION,
                ));
            }
        }

        return $actionProvider->values();
    }
}
