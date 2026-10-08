<?php

declare(strict_types=1);

namespace Strapi\Openapi\Assemblers\Document\Path\PathItem;

use Strapi\Openapi\Assemblers\Assembler;
use Strapi\Openapi\Context\Context;
use Strapi\Openapi\Context\Factories\PathItemContextFactory;
use Strapi\Openapi\Utils\Debug;

/** Port of packages/core/openapi/src/assemblers/document/path/path-item/path-item.ts. */
final class PathItemAssembler implements Assembler\Path
{
    /** @param list<Assembler\PathItem> $assemblers */
    public function __construct(
        private readonly array $assemblers,
        private readonly PathItemContextFactory $contextFactory,
    ) {
    }

    public function assemble(Context $context): void
    {
        $debug = Debug::createDebugger('assembler:path-item');
        $routes = $context->routes;

        $routesByPath = $this->groupRoutesByPath($routes);

        $debug('grouping routes by path, found %O groups for %O routes', count($routesByPath), count($routes));

        foreach ($routesByPath as $path => $pathRoutes) {
            $path = (string) $path;
            $openAPIPath = $this->formatPath($path);

            $debug(
                'assembling path item for %o (%o)...',
                $openAPIPath,
                implode(', ', array_map(static fn (array $route): string => (string) ($route['method'] ?? ''), $pathRoutes)),
            );

            $pathItemContext = $this->createPathItemContext($context);

            foreach ($this->assemblers as $assembler) {
                $debug('running assembler: %s...', $assembler::class);

                $assembler->assemble($pathItemContext, $path, $pathRoutes);
            }

            $context->output->data[$openAPIPath] = $pathItemContext->output->data;
        }
    }

    private function createPathItemContext(Context $context): Context
    {
        return $this->contextFactory->create([
            'strapi' => $context->strapi,
            'registries' => $context->registries,
            'routes' => $context->routes,
            'timer' => $context->timer,
        ]);
    }

    private function formatPath(string $path): string
    {
        return (string) preg_replace('/:([^\/]+)/', '{$1}', $path);
    }

    /**
     * @param list<array<string, mixed>> $routes
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupRoutesByPath(array $routes): array
    {
        $acc = [];
        foreach ($routes as $route) {
            $path = (string) ($route['path'] ?? '');
            $acc[$path] ??= [];
            $acc[$path][] = $route;
        }

        return $acc;
    }
}
