<?php

declare(strict_types=1);

namespace Strapi\Openapi\Routes\Providers;

use Strapi\Openapi\Utils\Debug;

/** Port of packages/core/openapi/src/routes/providers/admin.ts. */
final class AdminRoutesProvider extends AbstractRoutesProvider
{
    public function routes(): array
    {
        $admin = $this->strapi?->admin();
        $routers = is_array($admin) ? ($admin['routes'] ?? []) : [];

        $routes = self::flattenRouters($routers);

        Debug::createDebugger('routes:provider:admin')('found %o routes in Strapi admin', count($routes));

        return $routes;
    }
}
