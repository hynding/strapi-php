<?php

declare(strict_types=1);

use Strapi\Utils\EnvHelper;

// create-strapi-app templates/vanilla/config/server.ts
return static fn (EnvHelper $env): array => [
    'host' => $env('HOST', '0.0.0.0'),
    'port' => $env->int('PORT', 1337),
    'app' => [
        'keys' => $env->array('APP_KEYS'),
    ],
    'webhooks' => [
        'populateRelations' => $env->bool('WEBHOOKS_POPULATE_RELATIONS', false),
    ],
    'logger' => [
        'config' => ['level' => $env('LOG_LEVEL', 'warn')],
        'updates' => ['enabled' => false],
        'startup' => ['enabled' => false],
    ],
];
