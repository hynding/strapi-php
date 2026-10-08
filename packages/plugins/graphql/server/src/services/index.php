<?php

declare(strict_types=1);

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Services\Builders\Builders;
use Strapi\Plugin\Graphql\Services\Constants;
use Strapi\Plugin\Graphql\Services\ContentApi\ContentApi;
use Strapi\Plugin\Graphql\Services\Extension\Extension;
use Strapi\Plugin\Graphql\Services\Format\Format;
use Strapi\Plugin\Graphql\Services\Internals\Internals;
use Strapi\Plugin\Graphql\Services\TypeRegistry;
use Strapi\Plugin\Graphql\Services\Utils\Utils;

// Port of server/src/services/index.ts
return [
    'builders' => static fn (Strapi $strapi) => new Builders($strapi),
    'content-api' => static fn (Strapi $strapi) => new ContentApi($strapi),
    'constants' => static fn (Strapi $strapi) => new Constants(),
    'extension' => static fn (Strapi $strapi) => new Extension($strapi),
    'format' => static fn (Strapi $strapi) => new Format(),
    'internals' => static fn (Strapi $strapi) => new Internals($strapi),
    'type-registry' => static fn (Strapi $strapi) => new TypeRegistry(),
    'utils' => static fn (Strapi $strapi) => new Utils($strapi),
];
