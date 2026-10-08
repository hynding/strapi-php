<?php

declare(strict_types=1);

/**
 * Port of server/src/services/index.ts.
 *
 * history/ and preview/ are under Strapi's Enterprise licence (their own LICENSE file) and are
 * not ported: their services are not registered.
 */

use Strapi\ContentManager\Services;
use Strapi\Core\Strapi;

$homepage = require __DIR__ . '/../homepage/index.php';

return [
    'components' => static fn (Strapi $strapi): object => new Services\Components($strapi),
    'content-structure' => static fn (Strapi $strapi): object => new Services\ContentStructure($strapi),
    'content-types' => static fn (Strapi $strapi): object => new Services\ContentTypes($strapi),
    'data-mapper' => static fn (Strapi $strapi): object => new Services\DataMapper($strapi),
    'document-metadata' => static fn (Strapi $strapi): object => new Services\DocumentMetadata($strapi),
    'document-manager' => static fn (Strapi $strapi): object => new Services\DocumentManager($strapi),
    'field-sizes' => static fn (Strapi $strapi): object => new Services\FieldSizes($strapi),
    'metrics' => static fn (Strapi $strapi): object => new Services\Metrics($strapi),
    'permission-checker' => static fn (Strapi $strapi): object => new Services\PermissionChecker($strapi),
    'permission' => static fn (Strapi $strapi): object => new Services\Permission($strapi),
    'populate-builder' => static fn (Strapi $strapi): object => new Services\PopulateBuilder($strapi),
    'uid' => static fn (Strapi $strapi): object => new Services\Uid($strapi),
    ...$homepage['services'],
];
