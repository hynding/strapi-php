<?php

declare(strict_types=1);

use Strapi\Core\Factories;

return Factories::createCoreRouter('api::address.address', [
    'config' => [
        'find' => [
            // 'auth' => false,
        ],
    ],
    'only' => ['find', 'findOne'],
]);
