<?php

declare(strict_types=1);

namespace Strapi\Openapi\Routes\Providers;

use Strapi\Openapi\Utils\Debug;

/**
 * Port of packages/core/openapi/src/routes/providers/api.ts: the routes registered in the Strapi
 * APIs.
 */
final class ApiRoutesProvider extends AbstractRoutesProvider
{
    /**
     * Retrieves all routes registered in the Strapi APIs by flattening their routers into a
     * single list.
     */
    public function routes(): array
    {
        $routes = [];

        foreach ($this->strapi?->apis() ?? [] as $api) {
            // Extract and flatten each router from every API, then the routes from each router
            array_push($routes, ...self::flattenRouters($api->routes()));
        }

        Debug::createDebugger('routes:provider:api')('found %o routes in Strapi APIs', count($routes));

        return $routes;
    }
}
