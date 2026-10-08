<?php

declare(strict_types=1);

namespace Strapi\Openapi\Routes\Providers;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/openapi/src/routes/providers/abstract.ts: base class for the classes that
 * manage and provide routes from Strapi.
 */
abstract class AbstractRoutesProvider implements RoutesProvider
{
    /**
     * @param Strapi|null $strapi the Strapi instance used to retrieve routes (typed structurally,
     *   upstream tests pass partial mocks)
     */
    public function __construct(protected readonly ?object $strapi)
    {
    }

    /**
     * Classes extending this abstract class must provide their own implementation for returning
     * the list of routes they manage.
     *
     * @return list<array<string, mixed>>
     */
    abstract public function routes(): array;

    /** Iterator to traverse the routes (upstream `[Symbol.iterator]`). */
    public function getIterator(): \Generator
    {
        foreach ($this->routes() as $route) {
            yield $route;
        }
    }

    /**
     * Routers of a module: a list of routes or `Record<string, { routes }>`.
     *
     * @return list<array<string, mixed>>
     */
    protected static function flattenRouters(mixed $routers): array
    {
        $routes = [];
        if (!is_array($routers)) {
            return $routes;
        }
        foreach ($routers as $router) {
            if (is_array($router) && is_array($router['routes'] ?? null)) {
                foreach ($router['routes'] as $route) {
                    if (is_array($route)) {
                        $routes[] = $route;
                    }
                }
            }
        }

        return $routes;
    }
}
