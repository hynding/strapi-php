<?php

declare(strict_types=1);

namespace Strapi\Openapi\Routes;

use Strapi\Openapi\Routes\Providers\RoutesProvider;
use Strapi\Openapi\Utils\Debug;
use Strapi\Utils\Zod\ZodLazy;

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
        self::buildDeferredSchemas($routes);
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
     * PHP port: a settled boot defers the core routes' request and response schemas (`z.lazy()`,
     * Strapi\Core\CoreApi\Routes\Routes). They are built here, every route's in the order an
     * unsettled boot builds them at route registration (the providers' order, then `request` before
     * `response`), so that the content API's schema registry, which decides what becomes a
     * component, fills as it does upstream, and the document comes out the same.
     *
     * @param list<array<string, mixed>> $routes
     */
    private static function buildDeferredSchemas(array $routes): void
    {
        foreach ($routes as $route) {
            $request = $route['request'] ?? null;
            if (is_array($request)) {
                foreach ($request as $part) {
                    if (!is_array($part)) {
                        continue;
                    }
                    foreach ($part as $schema) {
                        if ($schema instanceof ZodLazy) {
                            $schema->unwrap();
                        }
                    }
                }
            }
            if (($route['response'] ?? null) instanceof ZodLazy) {
                $route['response']->unwrap();
            }
        }
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
