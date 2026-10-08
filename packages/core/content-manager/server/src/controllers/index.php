<?php

declare(strict_types=1);

/**
 * Port of server/src/controllers/index.ts.
 *
 * history/ and preview/ are under Strapi's Enterprise licence (their own LICENSE file) and are
 * not ported: their controllers are not registered.
 */

use Strapi\ContentManager\Controllers;
use Strapi\Core\Strapi;

$homepage = require __DIR__ . '/../homepage/index.php';

return [
    'collection-types' => static fn (Strapi $strapi): object => new Controllers\CollectionTypes($strapi),
    'components' => static fn (Strapi $strapi): object => new Controllers\Components($strapi),
    'content-types' => static fn (Strapi $strapi): object => new Controllers\ContentTypes($strapi),
    'init' => static fn (Strapi $strapi): object => new Controllers\Init($strapi),
    'relations' => static fn (Strapi $strapi): object => new Controllers\Relations($strapi),
    'single-types' => static fn (Strapi $strapi): object => new Controllers\SingleTypes($strapi),
    'uid' => static fn (Strapi $strapi): object => new Controllers\Uid($strapi),
    ...$homepage['controllers'],
];
