<?php

declare(strict_types=1);

/** Port of server/src/ai/routes/ai.ts. */

return [
    'type' => 'admin',
    'routes' => [
        [
            'method' => 'GET',
            'path' => '/ai-usage',
            'handler' => 'ai.getAiUsage',
            'config' => [
                'policies' => ['admin::isAuthenticatedAdmin'],
            ],
        ],
        [
            'method' => 'GET',
            'path' => '/ai-token',
            'handler' => 'ai.getAiToken',
            'config' => [
                'policies' => ['admin::isAuthenticatedAdmin'],
            ],
        ],
        [
            'method' => 'GET',
            'path' => '/ai-feature-config',
            'handler' => 'ai.getAiFeatureConfig',
            'config' => [
                'policies' => ['admin::isAuthenticatedAdmin'],
            ],
        ],
    ],
];
