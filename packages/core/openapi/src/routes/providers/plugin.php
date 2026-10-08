<?php

declare(strict_types=1);

namespace Strapi\Openapi\Routes\Providers;

use Strapi\Openapi\Utils\Debug;

/**
 * Port of packages/core/openapi/src/routes/providers/plugin.ts: the routes registered by Strapi
 * plugins, which define them either as a route list or as a record of routers.
 */
final class PluginRoutesProvider extends AbstractRoutesProvider
{
    public function routes(): array
    {
        $routes = [];

        foreach ($this->strapi?->plugins() ?? [] as $plugin) {
            $pluginRoutes = $plugin->routes();

            if (array_is_list($pluginRoutes)) {
                foreach ($pluginRoutes as $route) {
                    if (is_array($route)) {
                        $routes[] = $route;
                    }
                }
                continue;
            }

            foreach ($pluginRoutes as $router) {
                if (!is_array($router) || !is_array($router['routes'] ?? null)) {
                    continue;
                }

                foreach ($router['routes'] as $route) {
                    if (!is_array($route)) {
                        continue;
                    }

                    $hasOwnPrefix = is_array($route['config'] ?? null) && array_key_exists('prefix', $route['config']);

                    $effectivePrefix = $hasOwnPrefix
                        ? (string) ($route['config']['prefix'] ?? '')
                        : (string) ($router['prefix'] ?? '');

                    $fullPath = $effectivePrefix . (string) ($route['path'] ?? '');
                    $fullPath = $fullPath === '' ? '/' : $fullPath;
                    $fullPath = (string) preg_replace('/\/$/', '', (string) preg_replace('/\/+/', '/', $fullPath));

                    $routes[] = [...$route, 'path' => $fullPath === '' ? '/' : $fullPath];
                }
            }
        }

        Debug::createDebugger('routes:provider:plugins')('found %o routes in Strapi plugins', count($routes));

        return $routes;
    }
}
