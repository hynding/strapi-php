<?php

declare(strict_types=1);

namespace Strapi\Admin\Services;

use Strapi\Core\Strapi;

/** Port of server/src/services/condition.ts. */
final class Condition
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function isValidCondition(mixed $condition): bool
    {
        /** @var Permission $permissionService */
        $permissionService = $this->strapi->service('admin::permission');

        return is_string($condition) && $permissionService->conditionProvider->has($condition);
    }
}
