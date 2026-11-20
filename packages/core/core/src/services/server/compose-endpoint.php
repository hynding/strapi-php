<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Server;

use Strapi\Core\Registries\ActionMap;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ForbiddenError;
use Strapi\Utils\Errors\PolicyError;
use Strapi\Utils\Errors\UnauthorizedError;

/**
 * Port of packages/core/core/src/services/server/compose-endpoint.ts: composes the per-route
 * middleware chain (route info → authenticate → authorize → policies → route middlewares →
 * return-body → controller action) and registers it on a {@see Router}.
 */
final class ComposeEndpoint
{
    private readonly \Closure $authenticate;

    private readonly \Closure $authorize;

    public function __construct(private readonly Strapi $strapi)
    {
        $this->authenticate = fn (Context $ctx, callable $next) => $this->strapi->auth()->authenticate($ctx, $next);
        $this->authorize = function (Context $ctx, callable $next): void {
            $auth = $ctx->state()->auth();
            $route = $ctx->state()->route();

            try {
                $this->strapi->auth()->verify($auth, $route['config']['auth'] ?? []);
                $next();
            } catch (UnauthorizedError) {
                $ctx->unauthorized();
            } catch (PolicyError $error) {
                // allow PolicyError as an exception to throw a publicly visible message in the API
                throw $error;
            } catch (ForbiddenError) {
                $ctx->forbidden();
            }
        };
    }

    public static function createEndpointComposer(Strapi $strapi): self
    {
        return new self($strapi);
    }

    /** @param array<string, mixed> $route */
    private static function getMethod(array $route): string
    {
        return strtoupper(trim((string) $route['method']));
    }

    /** @param array<string, mixed> $route */
    private static function getPath(array $route): string
    {
        return trim((string) $route['path']);
    }

    /** @param array<string, mixed> $routeInfo */
    private static function createRouteInfoMiddleware(array $routeInfo): \Closure
    {
        return static function (Context $ctx, callable $next) use ($routeInfo): void {
            $route = [...$routeInfo, 'config' => $routeInfo['config'] ?? []];
            $ctx->state()->set('route', $route);
            $next();
        };
    }

    private static function returnBodyMiddleware(): \Closure
    {
        return static function (Context $ctx, callable $next): void {
            $values = $next();

            if ($ctx->body() === null && $values !== null) {
                $ctx->setBody($values);
            }
        };
    }

    /** @param array<string, mixed> $route */
    public function __invoke(array $route, Router $router): void
    {
        try {
            $method = self::getMethod($route);
            $path = self::getPath($route);

            $middlewares = Middleware::resolveRouteMiddlewares($route, $this->strapi);

            if ($this->strapi->bootstrapSettled()) {
                $router->add($method, $path, $this->deferredHandler($route, $middlewares), $route);

                return;
            }

            $router->add($method, $path, $this->composeHandler($route, $middlewares, $this->getAction($route)), $route);
        } catch (\Throwable $error) {
            throw new \RuntimeException("Error creating endpoint {$route['method']} {$route['path']}: {$error->getMessage()}", 0, $error);
        }
    }

    /**
     * @param array<string, mixed> $route
     * @param list<callable> $middlewares
     * @param list<callable> $action
     */
    private function composeHandler(array $route, array $middlewares, array $action): \Closure
    {
        return Compose::compose([
            self::createRouteInfoMiddleware($route),
            $this->authenticate,
            $this->authorize,
            Policy::createPoliciesMiddleware($route, $this->strapi),
            ...$middlewares,
            self::returnBodyMiddleware(),
            ...$action,
        ]);
    }

    /**
     * PHP port: on a settled boot (Strapi::bootstrapSettled(); under PHP-FPM, most requests) the
     * endpoint is composed when its route first matches. A boot serves one request, and composing
     * every endpoint (resolving and validating each route's policies, instantiating every
     * controller) was an eighth of a settled boot; the bootstrap that settled composed the same
     * routes from the same code without an error. The route's middlewares are still resolved
     * above (a middleware factory may extend the app), and its action is still bound to the route
     * type for the content-API permission map, which bootstrap reads next.
     *
     * @param array<string, mixed> $route
     * @param list<callable> $middlewares
     */
    private function deferredHandler(array $route, array $middlewares): \Closure
    {
        $handler = $route['handler'] ?? null;
        if (is_string($handler)) {
            $info = is_array($route['info'] ?? null) ? $route['info'] : [];
            ['controllerName' => $controllerName, 'actionName' => $actionName] = self::extractHandlerParts(trim($handler));
            $pluginName = is_string($info['pluginName'] ?? null) ? $info['pluginName'] : null;
            $apiName = is_string($info['apiName'] ?? null) ? $info['apiName'] : null;
            $type = $info['type'] ?? null;
            $this->strapi->contentAPI()->permissions->registerBoundAction($this->controllerUid($controllerName, $pluginName, $apiName), $actionName, is_string($type) ? $type : '');
        }

        $composed = null;

        return function (Context $ctx, ?callable $next = null) use (&$composed, $route, $middlewares): mixed {
            if ($composed === null) {
                try {
                    $composed = $this->composeHandler($route, $middlewares, $this->getAction($route, false));
                } catch (\Throwable $error) {
                    throw new \RuntimeException("Error creating endpoint {$route['method']} {$route['path']}: {$error->getMessage()}", 0, $error);
                }
            }

            return $composed($ctx, $next);
        };
    }

    /** @param array{pluginName?: string|null, apiName?: string|null, type?: string|null} $info */
    private function getController(string $name, array $info): object
    {
        $pluginName = $info['pluginName'] ?? null;
        $apiName = $info['apiName'] ?? null;
        $ctrl = null;

        if ($pluginName) {
            $ctrl = $pluginName === 'admin'
                ? $this->strapi->get('controllers')->get("admin::{$name}")
                : $this->strapi->get('controllers')->get("plugin::{$pluginName}.{$name}");
        } elseif ($apiName) {
            $ctrl = $this->strapi->get('controllers')->get("api::{$apiName}.{$name}");
        }

        if ($ctrl === null) {
            return $this->strapi->controller($name);
        }

        return $ctrl;
    }

    /** @return array{controllerName: string, actionName: string} */
    private static function extractHandlerParts(string $name): array
    {
        $pos = strrpos($name, '.');

        return [
            'controllerName' => $pos === false ? '' : substr($name, 0, $pos),
            'actionName' => $pos === false ? $name : substr($name, $pos + 1),
        ];
    }

    /**
     * The route's handler(s): a `controller.action` string resolves to one action, a callable
     * (closure, invokable or `[$object, 'method']`) is used as is, a list of callables is chained.
     *
     * @param array<string, mixed> $route
     * @param bool $bind whether to bind the action to the route type (a deferred endpoint did already)
     * @return list<callable>
     */
    private function getAction(array $route, bool $bind = true): array
    {
        $handler = $route['handler'];
        $info = $route['info'] ?? [];
        $pluginName = $info['pluginName'] ?? null;
        $apiName = $info['apiName'] ?? null;
        $type = $info['type'] ?? null;

        if (!is_string($handler) && is_callable($handler)) {
            return [$handler];
        }

        if (is_array($handler)) {
            $handlers = [];
            foreach ($handler as $item) {
                if (!is_callable($item)) {
                    throw new \RuntimeException('Invalid route handler: expected a controller action name, a callable or a list of callables');
                }
                $handlers[] = $item;
            }

            return $handlers;
        }

        ['controllerName' => $controllerName, 'actionName' => $actionName] = self::extractHandlerParts(trim((string) $handler));

        $controller = $this->getController($controllerName, ['pluginName' => $pluginName, 'apiName' => $apiName, 'type' => $type]);

        if (!ActionMap::hasAction($controller, $actionName)) {
            throw new \RuntimeException("Handler not found \"{$handler}\"");
        }

        // upstream tags the action with the route type (`__type__` symbol) for the content-API permission map
        if ($bind) {
            $controllerUid = $this->controllerUid($controllerName, $pluginName, $apiName);
            $this->strapi->contentAPI()->permissions->registerBoundAction($controllerUid, $actionName, (string) $type);
        }

        $action = ActionMap::action($controller, $actionName);

        return [static fn (Context $ctx, callable $next): mixed => $action($ctx, $next)];
    }

    private function controllerUid(string $controllerName, ?string $pluginName, ?string $apiName): string
    {
        if (str_contains($controllerName, '::')) {
            return $controllerName;
        }
        if ($pluginName) {
            return $pluginName === 'admin' ? "admin::{$controllerName}" : "plugin::{$pluginName}.{$controllerName}";
        }
        if ($apiName) {
            return "api::{$apiName}.{$controllerName}";
        }

        return $controllerName;
    }
}
