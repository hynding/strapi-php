<?php

declare(strict_types=1);

namespace Strapi\Openapi\Routes\Providers;

/**
 * Port of packages/core/openapi/src/routes/providers/types.ts: a provider for routes, iterable over
 * them (upstream `Iterable<Core.Route>`).
 *
 * @extends \IteratorAggregate<int, array<string, mixed>>
 */
interface RoutesProvider extends \IteratorAggregate
{
    /**
     * Retrieves an array of routes (upstream: the `routes` getter).
     *
     * @return list<array<string, mixed>>
     */
    public function routes(): array;
}
