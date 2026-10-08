<?php

declare(strict_types=1);

/** Port of server/src/controllers/index.ts. */

use Strapi\ContentTypeBuilder\Controllers;
use Strapi\Core\Strapi;

return [
    'builder' => static fn (Strapi $strapi): object => new Controllers\Builder($strapi),
    'component-categories' => static fn (Strapi $strapi): object => new Controllers\ComponentCategories($strapi),
    'components' => static fn (Strapi $strapi): object => new Controllers\Components($strapi),
    'content-types' => static fn (Strapi $strapi): object => new Controllers\ContentTypes($strapi),
    'schema' => static fn (Strapi $strapi): object => new Controllers\Schema($strapi),
];
