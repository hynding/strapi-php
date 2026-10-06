<?php

declare(strict_types=1);

namespace Strapi\Core\Services\ContentApi\Permissions;

use Strapi\Permissions\Engine\Engine as PermissionsEngine;

/** Port of packages/core/core/src/services/content-api/permissions/engine.ts. */
final class Engine
{
    /** @param array{providers: array{action: object, condition: object}} $options */
    public static function createPermissionEngine(array $options): PermissionsEngine
    {
        return PermissionsEngine::new(['providers' => $options['providers']]);
    }
}
