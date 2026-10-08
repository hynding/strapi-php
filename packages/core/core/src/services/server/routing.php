<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Server;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/services/server/routing.ts (`createRouteManager`, `validateRouteConfig`).
 */
final class Routing
{
    private readonly ComposeEndpoint $composeEndpoint;

    public function __construct(Strapi $strapi, private readonly ?string $type = null)
    {
        $this->composeEndpoint = ComposeEndpoint::createEndpointComposer($strapi);
    }

    /** @param array{type?: string|null} $opts */
    public static function createRouteManager(Strapi $strapi, array $opts = []): self
    {
        return new self($strapi, $opts['type'] ?? null);
    }

    /** @param array<string, mixed> $routeConfig */
    public static function validateRouteConfig(array $routeConfig): void
    {
        $errors = [];

        $method = $routeConfig['method'] ?? null;
        if (!is_string($method) || !in_array(strtoupper($method), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'ALL', 'HEAD', 'OPTIONS'], true)) {
            $errors[] = 'method must be one of the following values: GET, POST, PUT, PATCH, DELETE, ALL';
        }
        if (!is_string($routeConfig['path'] ?? null) || $routeConfig['path'] === '') {
            $errors[] = 'path is a required field';
        }
        $handler = $routeConfig['handler'] ?? null;
        if ($handler === null || (!is_string($handler) && !is_callable($handler) && !is_array($handler))) {
            $errors[] = 'handler is a required field';
        }

        $config = $routeConfig['config'] ?? null;
        if ($config !== null) {
            if (!is_array($config)) {
                $errors[] = 'config must be a `object` type';
            } else {
                if (array_key_exists('auth', $config) && $config['auth'] !== false && !(is_array($config['auth']) && isset($config['auth']['scope']) && is_array($config['auth']['scope']))) {
                    $errors[] = 'config.auth must be false or an object with a scope array';
                }
                foreach (['policies', 'middlewares'] as $key) {
                    if (array_key_exists($key, $config) && (!is_array($config[$key]) || !array_is_list($config[$key]))) {
                        $errors[] = "config.{$key} must be a `array` type";
                    }
                }
            }
        }

        if ($errors !== []) {
            throw new \RuntimeException('Invalid route config ' . implode(', ', $errors));
        }
    }

    /** @param array<string, mixed> $route */
    private function createRoute(array $route, Router $router): void
    {
        self::validateRouteConfig($route);

        // NOTE: the router type is used to tag controller actions and for authentication / authorization
        $routeWithInfo = [...$route, 'info' => [...($route['info'] ?? []), 'type' => $this->type ?? 'api']];

        ($this->composeEndpoint)($routeWithInfo, $router);
    }

    /**
     * @param array<string, mixed>|list<array<string, mixed>> $routes a router (`['type', 'prefix', 'routes' => [...]]`) or a list of routes
     */
    public function addRoutes(array $routes, Router $router): void
    {
        if (array_is_list($routes)) {
            foreach ($routes as $route) {
                $this->createRoute($route, $router);
            }

            return;
        }

        if (isset($routes['routes']) && is_array($routes['routes'])) {
            $subRouter = new Router(Router::joinPath($router->prefix(), (string) ($routes['prefix'] ?? '')));
            $rootRouter = new Router($router->prefix());

            foreach ($routes['routes'] as $route) {
                $hasPrefix = is_array($route['config'] ?? null) && array_key_exists('prefix', $route['config']);
                if ($hasPrefix) {
                    $this->createRoute([...$route, 'path' => Router::joinPath((string) $route['config']['prefix'], (string) $route['path'])], $rootRouter);
                } else {
                    $this->createRoute($route, $subRouter);
                }
            }

            $router->use($rootRouter);
            $router->use($subRouter);
        }
    }
}
