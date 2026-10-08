<?php

declare(strict_types=1);

// Port of server/src/routes/authentication.ts
return [
    [
        'method' => 'POST',
        'path' => '/login',
        'handler' => 'authentication.login',
        'config' => [
            'auth' => false,
            'middlewares' => ['admin::rateLimit'],
        ],
    ],
    [
        'method' => 'POST',
        'path' => '/access-token',
        'handler' => 'authentication.accessToken',
        'config' => ['auth' => false],
    ],
    [
        'method' => 'POST',
        'path' => '/register-admin',
        'handler' => 'authentication.registerAdmin',
        'config' => [
            'auth' => false,
            'middlewares' => ['admin::rateLimit'],
        ],
    ],
    [
        'method' => 'GET',
        'path' => '/registration-info',
        'handler' => 'authentication.registrationInfo',
        'config' => ['auth' => false],
    ],
    [
        'method' => 'POST',
        'path' => '/register',
        'handler' => 'authentication.register',
        'config' => ['auth' => false],
    ],
    [
        'method' => 'POST',
        'path' => '/forgot-password',
        'handler' => 'authentication.forgotPassword',
        'config' => [
            'auth' => false,
            // upstream: middlewares: ['plugin::email.rateLimit']. strapi/email is not ported yet and
            // route middlewares are resolved when the route is registered (an unknown one aborts
            // the boot): restore it together with the email plugin port.
            'middlewares' => [],
        ],
    ],
    [
        'method' => 'POST',
        'path' => '/reset-password',
        'handler' => 'authentication.resetPassword',
        'config' => ['auth' => false],
    ],
    [
        'method' => 'POST',
        'path' => '/logout',
        'handler' => 'authentication.logout',
        'config' => ['policies' => ['admin::isAuthenticatedAdmin']],
    ],
];
