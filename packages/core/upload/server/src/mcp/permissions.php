<?php

declare(strict_types=1);

namespace Strapi\Upload\Mcp;

use Strapi\Admin\Services\Permission\PermissionsManager\PermissionsManager;
use Strapi\Core\Strapi;
use Strapi\Upload\Utils\Utils as UploadUtils;
use Strapi\Utils\Errors\ForbiddenError;

/**
 * Port of server/src/mcp/permissions.ts.
 *
 * @phpstan-import-type McpHandlerContext from Types
 */
final class Permissions
{
    /**
     * Builds an admin permissions manager for a media model bound to the MCP session's ability.
     *
     * MCP tool handlers have no Koa context, so they cannot rely on route policies: each handler must
     * re-check permissions itself, exactly as the admin controllers do via `ctx.state.userAbility`.
     *
     * @param McpHandlerContext $context
     */
    public static function createMediaPermissionsManager(Strapi $strapi, array $context, string $action, string $model): PermissionsManager
    {
        return UploadUtils::permissionService($strapi)->createPermissionsManager([
            'ability' => $context['userAbility'],
            'action' => $action,
            'model' => $model,
        ]);
    }

    /**
     * Throws `ForbiddenError` unless the session's ability permits `action` on `model`.
     * Mirrors the `if (!pm.isAllowed) return ctx.forbidden()` guard in the admin controllers.
     *
     * @param McpHandlerContext $context
     */
    public static function assertMediaPermission(Strapi $strapi, array $context, string $action, string $model): PermissionsManager
    {
        $pm = self::createMediaPermissionsManager($strapi, $context, $action, $model);

        if (!$pm->isAllowed()) {
            throw new ForbiddenError();
        }

        return $pm;
    }
}
