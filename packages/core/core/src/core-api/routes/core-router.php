<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Routes;

use Strapi\Core\Strapi;

/**
 * The lazy router object returned by `createCoreRouter` (factories.ts): its routes are built from the
 * registered content type the first time they are read (at `initRouting`), with the `config`,
 * `only` and `except` options applied.
 *
 * @phpstan-type RouterConfig array{prefix?: string, config?: array<string, array<string, mixed>>, only?: list<string>, except?: list<string>, type?: string}
 */
final class CoreRouter
{
    public readonly string $type;

    public readonly ?string $prefix;

    /** @var list<array<string, mixed>>|null */
    private ?array $routes = null;

    /** @param RouterConfig $cfg */
    public function __construct(public readonly string $uid, private readonly array $cfg = [])
    {
        $this->type = $cfg['type'] ?? 'content-api';
        $this->prefix = $cfg['prefix'] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function routes(Strapi $strapi): array
    {
        if ($this->routes === null) {
            $contentType = $strapi->contentType($this->uid);
            $config = $this->cfg['config'] ?? [];

            $defaultRoutes = Routes::createRoutes($strapi, $contentType);

            foreach ($defaultRoutes as $routeName => $route) {
                $defaultRoutes[$routeName]['config'] = $config[$routeName] ?? [];
            }

            $availableRoutes = isset($this->cfg['except']) ? array_diff_key($defaultRoutes, array_flip($this->cfg['except'])) : $defaultRoutes;
            $selectedRoutes = isset($this->cfg['only']) ? array_intersect_key($availableRoutes, array_flip($this->cfg['only'])) : $availableRoutes;

            $this->routes = array_values($selectedRoutes);
        }

        return $this->routes;
    }

    /** @return array{type: string, prefix?: string, routes: list<array<string, mixed>>} */
    public function toArray(Strapi $strapi): array
    {
        $out = ['type' => $this->type, 'routes' => $this->routes($strapi)];
        if ($this->prefix !== null) {
            $out['prefix'] = $this->prefix;
        }

        return $out;
    }
}
