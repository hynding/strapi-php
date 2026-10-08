<?php

declare(strict_types=1);

namespace Strapi\Openapi\Routes;

use Strapi\Openapi\Routes\Providers\RoutesProvider;
use Strapi\Openapi\Utils\Debug;

/** Port of packages/core/openapi/src/routes/collector.ts: collects and filters routes from multiple providers. */
final class RouteCollector
{
    /** @var list<RoutesProvider> */
    private readonly array $providers;

    private readonly RouteMatcher $matcher;

    /**
     * @param list<RoutesProvider> $providers route providers to collect routes from
     * @param RouteMatcher|null $matcher filters routes; defaults to a matcher with no rules
     */
    public function __construct(array $providers = [], ?RouteMatcher $matcher = null)
    {
        $this->providers = $providers;
        $this->matcher = $matcher ?? new RouteMatcher();
    }

    /**
     * Collects routes from all providers and filters them based on the matcher rules.
     *
     * @return list<array<string, mixed>>
     */
    public function collect(): array
    {
        $routes = [];
        foreach ($this->providers as $provider) {
            foreach ($provider as $route) {
                $routes[] = $route;
            }
        }
        $sanitizedRoutes = $this->filter($routes);

        Debug::createDebugger('routes:collector')(
            'collected %o/%o routes from %o providers %o',
            count($sanitizedRoutes),
            count($routes),
            count($this->providers),
            array_map(static fn (RoutesProvider $provider): string => $provider::class, $this->providers),
        );

        return $sanitizedRoutes;
    }

    /**
     * @param list<array<string, mixed>> $routes
     *
     * @return list<array<string, mixed>>
     */
    private function filter(array $routes): array
    {
        return array_values(array_filter($routes, fn (array $route): bool => $this->matcher->match($route)));
    }
}
