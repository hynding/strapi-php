<?php

declare(strict_types=1);

return [
    'routes' => [
        [
            'method' => 'GET',
            'path' => '/{{ id }}',
            'handler' => '{{ id }}.find',
            'config' => [
                'policies' => [],
                'middlewares' => [],
            ],
        ],
        [
            'method' => 'PUT',
            'path' => '/{{ id }}',
            'handler' => '{{ id }}.update',
            'config' => [
                'policies' => [],
                'middlewares' => [],
            ],
        ],
        [
            'method' => 'DELETE',
            'path' => '/{{ id }}',
            'handler' => '{{ id }}.delete',
            'config' => [
                'policies' => [],
                'middlewares' => [],
            ],
        ],
    ],
];
