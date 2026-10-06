<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Server;

use Strapi\Core\Strapi;

/** Port of packages/core/core/src/services/server/api.ts: a prefixed router with its own route manager. */
class Api
{
    protected readonly Router $router;

    protected readonly Routing $routeManager;

    /** @var list<callable> */
    protected array $middlewares = [];

    /** @param array{prefix?: string, type?: string} $opts */
    public function __construct(protected readonly Strapi $strapi, array $opts = [])
    {
        $this->router = new Router($opts['prefix'] ?? '');
        $this->routeManager = Routing::createRouteManager($strapi, ['type' => $opts['type'] ?? null]);
    }

    /** @param array{prefix?: string, type?: string} $opts */
    public static function createAPI(Strapi $strapi, array $opts = []): static
    {
        return new static($strapi, $opts);
    }

    public function prefix(): string
    {
        return $this->router->prefix();
    }

    /** @return list<array{method: string, path: string, handler: callable, route: array<string, mixed>}> */
    public function listRoutes(): array
    {
        return $this->router->stack();
    }

    /** API-level middleware (`api.use(fn)`): wraps every route of this API. */
    public function use(callable $fn): static
    {
        $this->middlewares[] = $fn;

        return $this;
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $routes */
    public function routes(array $routes): static
    {
        $this->routeManager->addRoutes($routes, $this->router);

        return $this;
    }

    public function mount(Router $router): static
    {
        if ($this->middlewares === []) {
            $router->use($this->router);

            return $this;
        }

        foreach ($this->router->stack() as $entry) {
            $handler = Compose::compose([...$this->middlewares, $entry['handler']]);
            $router->add($entry['method'], $entry['path'], $handler, $entry['route']);
        }

        return $this;
    }
}
