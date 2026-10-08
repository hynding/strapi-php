<?php

declare(strict_types=1);

use Strapi\Utils\EnvHelper;

// Port of server/src/config/default-config.ts
return static fn (EnvHelper $env): array => [
    'shadowCRUD' => true,
    'endpoint' => '/graphql',
    'subscriptions' => false,
    'maxLimit' => -1,
    'apolloServer' => [],
    'v4CompatibilityMode' => $env('STRAPI_GRAPHQL_V4_COMPATIBILITY_MODE') ?? false,
];
