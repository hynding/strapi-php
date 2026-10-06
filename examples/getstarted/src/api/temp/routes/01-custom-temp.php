<?php

declare(strict_types=1);

/**
 * Custom routes of the temp API (not part of upstream getstarted): exercise a policy denial and a
 * custom controller action. Route files named `01-*` are registered before the core router.
 */
return [
    'type' => 'content-api',
    'routes' => [
        [
            'method' => 'GET',
            'path' => '/temps/denied',
            'handler' => 'temp.find',
            'config' => [
                'policies' => ['global::deny'],
            ],
        ],
        [
            'method' => 'GET',
            'path' => '/temps/ping',
            'handler' => 'temp.ping',
            'config' => [
                'auth' => false,
                'policies' => ['global::test-policy'],
                'middlewares' => ['api::address.address-middleware'],
            ],
        ],
    ],
];
