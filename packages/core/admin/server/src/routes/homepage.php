<?php

declare(strict_types=1);

/** Port of server/src/routes/homepage.ts. */

return [
    [
        'method' => 'GET',
        'path' => '/homepage/key-statistics',
        'handler' => 'homepage.getKeyStatistics',
        'config' => [
            'policies' => [
                'admin::isAuthenticatedAdmin',
            ],
        ],
    ],
    [
        'method' => 'GET',
        'path' => '/homepage/layout',
        'handler' => 'homepage.getHomepageLayout',
        'config' => [
            'policies' => [
                'admin::isAuthenticatedAdmin',
            ],
        ],
    ],
    [
        'method' => 'PUT',
        'path' => '/homepage/layout',
        'handler' => 'homepage.updateHomepageLayout',
        'config' => [
            'policies' => [
                'admin::isAuthenticatedAdmin',
            ],
        ],
    ],
];
