<?php

declare(strict_types=1);

/** Port of server/src/config.ts: the plugin's default config and its (no-op) validator. */
return [
    'default' => [
        'provider' => 'sendmail',
        'providerOptions' => [],
        'settings' => [
            'defaultFrom' => 'Strapi <no-reply@strapi.io>',
        ],
    ],
    'validator' => static function (): void {
    },
];
