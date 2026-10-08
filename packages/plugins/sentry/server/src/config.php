<?php

declare(strict_types=1);

/**
 * Port of server/src/config.ts. `init` is passed to the client like upstream passes it to
 * `Sentry.init()` (see {@see \Strapi\Plugin\Sentry\Sdk\Client} for the supported options).
 *
 * @phpstan-type Config array{dsn: string|null, sendMetadata: bool, init: array<string, mixed>}
 */
return [
    'default' => [
        'dsn' => null,
        'sendMetadata' => true,
        'init' => [],
    ],
    'validator' => static function (): void {
    },
];
