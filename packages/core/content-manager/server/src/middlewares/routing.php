<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Middlewares;

use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Core\Registries\ActionMap;

/**
 * Port of server/src/middlewares/routing.ts.
 *
 * Routes reference it as `['resolve' => Routing::class]`: core instantiates the class and calls it
 * as a middleware factory `(config, strapi)`, which gives the middleware the Strapi instance
 * upstream reads from the global.
 */
final class Routing
{
    /** @param array<string, mixed> $config */
    public function __invoke(array $config, Strapi $strapi): \Closure
    {
        return static function (Context $ctx, callable $next) use ($strapi): mixed {
            $model = (string) $ctx->param('model');

            $ct = $strapi->contentTypes()[$model] ?? null;

            if ($ct === null) {
                $ctx->send(['error' => 'contentType.notFound'], 404);

                return null;
            }

            $isAdmin = $ct->plugin === null || $ct->plugin === '' || $ct->plugin === 'admin';

            $route = $ctx->state()->route();
            $handler = $route['handler'] ?? null;

            if (!is_string($handler)) {
                return $next();
            }

            $action = explode('.', $handler)[1] ?? null;

            $actionConfig = $isAdmin
                ? $strapi->config()->get("admin.layout.{$ct->modelName}.actions.{$action}")
                : $strapi->plugin((string) $ct->plugin)->config("layout.{$ct->modelName}.actions.{$action}");

            if ($actionConfig !== null && is_string($actionConfig)) {
                $parts = explode('.', $actionConfig);
                $controllerName = $parts[0];
                $actionName = $parts[1] ?? null;

                if ($controllerName !== '' && $actionName !== null && $actionName !== '') {
                    $controllerName = strtolower($controllerName);
                    $controller = $isAdmin
                        ? $strapi->controller("admin::{$controllerName}")
                        : $strapi->plugin((string) $ct->plugin)->controller($controllerName);

                    return ActionMap::action($controller, $actionName)($ctx, $next);
                }
            }

            return $next();
        };
    }
}
