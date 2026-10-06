<?php

declare(strict_types=1);

$responseHandlers = require __DIR__ . '/src/response-handlers.php';

return [
    'strapi::logger',
    'strapi::errors',
    [
        'name' => 'strapi::security',
        'config' => [
            'contentSecurityPolicy' => [
                'useDefaults' => true,
                'directives' => [
                    'frame-src' => ["'self'"], // URLs that will be loaded in an iframe (e.g. Content Preview)
                    // Needed to load the `@vercel/stega` lib on the dummy-preview page
                    'script-src' => ["'self'", "'unsafe-inline'", 'https://cdn.jsdelivr.net'],
                ],
            ],
        ],
    ],
    'strapi::cors',
    'strapi::poweredBy',
    'strapi::query',
    'strapi::body',
    'strapi::session',
    // 'strapi::compression',
    // 'strapi::ip',
    [
        'name' => 'strapi::responses',
        'config' => [
            'handlers' => $responseHandlers,
        ],
    ],
    'strapi::favicon',
    'strapi::public',
    [
        'name' => 'global::test-middleware',
        'config' => [
            'foo' => 'bar',
        ],
    ],
    [
        'resolve' => './src/custom/middleware.php',
        'config' => [],
    ],
];
