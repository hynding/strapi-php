<?php

declare(strict_types=1);

use Strapi\Core\Strapi;

return static fn (Strapi $strapi): array => [
    'type' => '{{ type }}',
    'routes' => [],
];
