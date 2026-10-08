<?php

declare(strict_types=1);

/** Port of server/src/services/index.ts. */

use Strapi\ContentTypeBuilder\Services;
use Strapi\Core\Strapi;

return [
    'content-types' => static fn (Strapi $strapi): object => new Services\ContentTypes($strapi),
    'components' => static fn (Strapi $strapi): object => new Services\Components($strapi),
    'component-categories' => static fn (Strapi $strapi): object => new Services\ComponentCategories($strapi),
    'builder' => static fn (Strapi $strapi): object => new Services\Builder(),
    'api-handler' => static fn (Strapi $strapi): object => new Services\ApiHandler($strapi),
    'schema' => static fn (Strapi $strapi): object => new Services\Schema($strapi),
    'content-structure' => static fn (Strapi $strapi): object => Services\ContentStructure::createContentStructureService($strapi),
];
