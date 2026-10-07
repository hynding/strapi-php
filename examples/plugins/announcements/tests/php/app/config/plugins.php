<?php

declare(strict_types=1);

/** The plugin under test, resolved from its directory (no Composer install needed). */
return static fn (): array => [
    'announcements' => [
        'enabled' => true,
        'resolve' => dirname(__DIR__, 4),
    ],
];
