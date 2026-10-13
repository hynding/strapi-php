<?php

declare(strict_types=1);

use Strapi\Utils\EnvHelper;

return static fn (EnvHelper $env): array => [
    'url' => $env('PUBLIC_URL', 'https://example.com'),
    'proxy' => true,
    'cron' => ['enabled' => $env->bool('CRON_ENABLED', false)],
];
