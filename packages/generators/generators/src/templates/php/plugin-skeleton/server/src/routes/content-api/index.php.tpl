<?php

declare(strict_types=1);

use Strapi\Core\Strapi;

return static fn (Strapi $strapi): array => [
    'type' => 'content-api',
    'routes' => [
        [
            'method' => 'GET',
            'path' => '/',
            // name of the controller file & the method.
            'handler' => 'controller.index',
            'config' => [
                'policies' => [],
            ],
        ],
    ],
];
