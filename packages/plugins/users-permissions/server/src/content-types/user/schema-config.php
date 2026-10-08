<?php

declare(strict_types=1);

/** Port of server/src/content-types/user/schema-config.js. */
return [
    'attributes' => [
        'resetPasswordToken' => [
            'hidden' => true,
        ],
        'confirmationToken' => [
            'hidden' => true,
        ],
        'provider' => [
            'hidden' => true,
        ],
    ],
];
