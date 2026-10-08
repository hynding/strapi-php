<?php

declare(strict_types=1);

/** Port of server/src/homepage/routes/homepage.ts. */

$info = ['pluginName' => 'content-manager', 'type' => 'admin'];

return [
    'type' => 'admin',
    'routes' => [
        [
            'method' => 'GET',
            'info' => $info,
            'path' => '/homepage/recent-documents',
            'handler' => 'homepage.getRecentDocuments',
            'config' => [
                'policies' => ['admin::isAuthenticatedAdmin'],
            ],
        ],
        [
            'method' => 'GET',
            'info' => $info,
            'path' => '/homepage/count-documents',
            'handler' => 'homepage.getCountDocuments',
            'config' => [
                'policies' => ['admin::isAuthenticatedAdmin'],
            ],
        ],
    ],
];
