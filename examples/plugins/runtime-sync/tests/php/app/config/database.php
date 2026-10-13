<?php

declare(strict_types=1);

use Strapi\Utils\EnvHelper;

/** In-memory by default; RUNTIME_SYNC_TEST_DB names a file so a second boot sees the first one's schema. */
return static fn (EnvHelper $env): array => [
    'connection' => [
        'client' => 'sqlite',
        'connection' => ['filename' => $env('RUNTIME_SYNC_TEST_DB', ':memory:')],
        'useNullAsDefault' => true,
    ],
];
