<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Server;

use Strapi\Core\Strapi;

/** Port of packages/core/core/src/services/server/register-routes.ts. */
final class RegisterRoutes
{
    /** @return \Closure(array<string, mixed>&): void */
    private static function createRouteScopeGenerator(string $namespace): \Closure
    {
        $prefix = str_ends_with($namespace, '::') ? $namespace : "{$namespace}.";

        return static function (array &$route) use ($prefix): void {
            if (is_string($route['handler'] ?? null)) {
                $handler = $route['handler'];
                $scope = (str_starts_with($handler, $prefix) ? '' : $prefix) . $handler;
                $route['config'] ??= [];
                if (!is_array($route['config'])) {
                    $route['config'] = [];
                }
                if (($route['config']['auth'] ?? null) === false) {
                    return;
                }
                $route['config']['auth'] ??= [];
                if (is_array($route['config']['auth']) && !array_key_exists('scope', $route['config']['auth'])) {
                    $route['config']['auth']['scope'] = [$scope];
                }
            }
        };
    }

    /** Register all routes. */
    public static function registerAllRoutes(Strapi $strapi): void
    {
        self::registerAdminRoutes($strapi);
        self::registerAPIRoutes($strapi);
        self::registerPluginRoutes($strapi);
        // registerOpenAPIRoute: the OpenAPI generator (services/server/openapi.ts) is not ported
    }

    private static function registerAdminRoutes(Strapi $strapi): void
    {
        $admin = $strapi->has('admin') ? $strapi->get('admin') : null;
        if (!is_array($admin) || !isset($admin['routes']) || !is_array($admin['routes'])) {
            return;
        }
        $generateRouteScope = self::createRouteScopeGenerator('admin::');
        $routers = self::instantiateRouterInputs($admin['routes'], $strapi);

        foreach ($routers as $router) {
            $router['type'] ??= 'admin';
            $router['prefix'] ??= '/admin';
            foreach ($router['routes'] as &$route) {
                $generateRouteScope($route);
                $route['info'] = ['pluginName' => 'admin'];
            }
            unset($route);
            $strapi->server()->routes($router);
        }
    }

    private static function registerPluginRoutes(Strapi $strapi): void
    {
        foreach ($strapi->plugins() as $pluginName => $plugin) {
            $pluginName = (string) $pluginName;
            $generateRouteScope = self::createRouteScopeGenerator("plugin::{$pluginName}");
            $routes = $plugin->routes();

            if (array_is_list($routes)) {
                $routes = array_map(static function (array $route) use ($generateRouteScope, $pluginName): array {
                    $generateRouteScope($route);
                    $route['info'] = ['pluginName' => $pluginName];

                    return $route;
                }, $routes);
                $strapi->contentAPI()->applyExtraParamsToRoutes($routes);
                $plugin->setRoutes($routes);

                $strapi->server()->routes(['type' => 'admin', 'prefix' => "/{$pluginName}", 'routes' => $routes]);
                continue;
            }

            $routers = self::instantiateRouterInputs($routes, $strapi);
            foreach ($routers as $key => $router) {
                $router['type'] ??= 'admin';
                $router['prefix'] ??= "/{$pluginName}";
                $router['routes'] ??= [];
                foreach ($router['routes'] as &$route) {
                    $generateRouteScope($route);
                    $route['info'] = ['pluginName' => $pluginName];
                }
                unset($route);
                $strapi->contentAPI()->applyExtraParamsToRoutes($router['routes']);
                $routers[$key] = $router;

                $strapi->server()->routes($router);
            }
            $plugin->setRoutes($routers);
        }
    }

    private static function registerAPIRoutes(Strapi $strapi): void
    {
        foreach ($strapi->apis() as $apiName => $api) {
            $apiName = (string) $apiName;
            $generateRouteScope = self::createRouteScopeGenerator("api::{$apiName}");

            $routers = self::instantiateRouterInputs($api->routes(), $strapi);

            foreach ($routers as $key => $router) {
                // pass meta down to compose endpoint
                $router['type'] = 'content-api';
                $router['routes'] ??= [];
                foreach ($router['routes'] as &$route) {
                    $generateRouteScope($route);
                    $route['info'] = ['apiName' => $apiName];
                }
                unset($route);
                $strapi->contentAPI()->applyExtraParamsToRoutes($router['routes']);
                $routers[$key] = $router;

                $strapi->server()->routes($router);
            }
            $api->setRoutes($routers);
        }
    }

    /**
     * Router inputs may be arrays, `callable(Strapi): array` factories or {@see \Strapi\Core\CoreApi\Routes\CoreRouter}s.
     *
     * @param array<string, mixed>|list<mixed> $routers
     * @return array<string, array<string, mixed>>
     */
    private static function instantiateRouterInputs(array $routers, Strapi $strapi): array
    {
        $out = [];
        foreach ($routers as $key => $inputOrCallback) {
            $router = $inputOrCallback;
            if ($router instanceof \Strapi\Core\CoreApi\Routes\CoreRouter) {
                $router = $router->toArray($strapi);
            } elseif (!is_array($router) && is_callable($router)) {
                $router = $router($strapi);
            }
            if ($router instanceof \Strapi\Core\CoreApi\Routes\CoreRouter) {
                $router = $router->toArray($strapi);
            }
            if (!is_array($router)) {
                throw new \RuntimeException("Invalid router \"{$key}\": expected an array or a factory");
            }
            // a bare list of routes becomes a router
            if (array_is_list($router)) {
                $router = ['routes' => $router];
            }
            $out[(string) $key] = $router;
        }

        return $out;
    }
}
