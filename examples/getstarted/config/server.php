<?php

declare(strict_types=1);

use Strapi\Utils\EnvHelper;

$cronTasks = require __DIR__ . '/src/cron-tasks.php';

return static fn (EnvHelper $env): array => [
    'host' => $env('HOST', '0.0.0.0'),
    'port' => $env->int('PORT', 1337),
    'cron' => [
        'enabled' => true,
        'tasks' => $cronTasks,
    ],
    'app' => [
        'keys' => $env->array('APP_KEYS', ['toBeModified1', 'toBeModified2']),
    ],
    'webhooks' => [
        // Receive populated relations in webhook and db lifecycle payloads
        'populateRelations' => $env->bool('WEBHOOKS_POPULATE_RELATIONS', true),
    ],
    // http_proxy is the env var used by system to set proxy globally
    'proxy' => [
        'global' => $env('http_proxy'),
    ],
    'http' => [
        'serverOptions' => [
            'requestTimeout' => 1000 * 60 * 10, // 600000ms (10 minutes)
        ],
    ],
    'transfer' => [
        'remote' => [
            // 'enabled' => false,
        ],
    ],
    'logger' => [
        'config' => [
            // PHP edition: LOG_LEVEL overrides (the test suites set it to `error`)
            'level' => $env('LOG_LEVEL', 'silly'),
        ],
        'updates' => [
            // 'enabled' => false,
        ],
        'startup' => [
            // 'enabled' => false,
        ],
    ],
    'mcp' => [
        'enabled' => true,
    ],
];
