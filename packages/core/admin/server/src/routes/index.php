<?php

declare(strict_types=1);

/** Port of server/src/routes/index.ts. */

$load = static fn (string $file): array => require __DIR__ . "/{$file}.php";
$aiRoutes = require __DIR__ . '/../ai/routes/ai.php';

return [
    'admin' => [
        'type' => 'admin',
        'routes' => [
            ...$load('admin'),
            ...$load('authentication'),
            ...$load('permissions'),
            ...$load('users'),
            ...$load('roles'),
            ...$load('webhooks'),
            ...$load('api-tokens'),
            ...$load('admin-tokens'),
            ...$load('content-api'),
            ...$load('transfer'),
            ...$load('homepage'),
            ...$aiRoutes['routes'],
        ],
    ],
];
