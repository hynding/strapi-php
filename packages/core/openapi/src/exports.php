<?php

declare(strict_types=1);

namespace Strapi\Openapi;

use Strapi\Core\Strapi;
use Strapi\Openapi\Assemblers\Document\DocumentAssemblerFactory;
use Strapi\Openapi\Context\Factories\DocumentContextFactory;
use Strapi\Openapi\Generator\OpenAPIGenerator;
use Strapi\Openapi\PostProcessor\PostProcessorsFactory;
use Strapi\Openapi\PreProcessor\PreProcessorFactory;
use Strapi\Openapi\Routes\Providers\AdminRoutesProvider;
use Strapi\Openapi\Routes\Providers\ApiRoutesProvider;
use Strapi\Openapi\Routes\Providers\PluginRoutesProvider;
use Strapi\Openapi\Routes\RouteCollector;
use Strapi\Openapi\Routes\RouteMatcher;
use Strapi\Openapi\Routes\Rules\IsOfType;

/**
 * Port of packages/core/openapi/src/exports.ts (the package entry, `@strapi/openapi`).
 *
 * @phpstan-import-type GenerationOptions from Types
 * @phpstan-import-type GeneratorOutput from \Strapi\Openapi\Generator\Types
 */
final class Exports
{
    /**
     * Generates an in-memory OpenAPI specification for Strapi routes.
     *
     * @experimental
     *
     * @param Strapi $strapi the Strapi application instance
     * @param GenerationOptions|null $options `type`: the type of routes to generate documentation
     *   for, either 'admin' or 'content-api'. Defaults to 'content-api'.
     *
     * @return GeneratorOutput the generated OpenAPI document and the generation duration
     *
     * ```php
     * $output = \Strapi\Openapi\Exports::generate($strapi, ['type' => 'content-api']);
     * echo json_encode($output['document']);
     * ```
     */
    public static function generate(object $strapi, ?array $options = null): array
    {
        $type = $options['type'] ?? 'content-api';

        $config = [
            'preProcessors' => (new PreProcessorFactory())->createAll(),
            'assemblers' => (new DocumentAssemblerFactory())->createAll(),
            'postProcessors' => (new PostProcessorsFactory())->createAll(),
        ];

        // Data sources for the Strapi routes
        $routeCollector = new RouteCollector(
            [
                new AdminRoutesProvider($strapi),
                new ApiRoutesProvider($strapi),
                new PluginRoutesProvider($strapi),
            ],
            new RouteMatcher([
                // Only match content-api routes
                IsOfType::isOfType($type),
            ]),
        );

        $contextFactory = new DocumentContextFactory();

        $generator = new OpenAPIGenerator($config, $strapi, $routeCollector, $contextFactory);

        return $generator->generate();
    }
}
