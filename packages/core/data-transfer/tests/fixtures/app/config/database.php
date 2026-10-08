<?php

declare(strict_types=1);

use Strapi\Utils\EnvHelper;

return static fn (EnvHelper $env): array => [
    'connection' => [
        'client' => 'sqlite',
        'connection' => ['filename' => $env('DATABASE_FILENAME', '.tmp/data.db')],
        'useNullAsDefault' => true,
    ],
];
