<?php

declare(strict_types=1);

/** Port of server/src/content-types/index.ts. */

$schema = static fn (string $file): array => ['schema' => require __DIR__ . "/{$file}.php"];

return [
    'permission' => $schema('Permission'),
    'user' => $schema('User'),
    'role' => $schema('Role'),
    'api-token' => $schema('api-token'),
    'api-token-permission' => $schema('api-token-permission'),
    'transfer-token' => $schema('transfer-token'),
    'transfer-token-permission' => $schema('transfer-token-permission'),
    'session' => $schema('session'),
];
