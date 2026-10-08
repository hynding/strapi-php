<?php

declare(strict_types=1);

return static fn (): array => [
    'host' => '127.0.0.1',
    'port' => 1337,
    'app' => ['keys' => ['conformance-1', 'conformance-2']],
    'logger' => [
        'config' => ['level' => 'error'],
        'updates' => ['enabled' => false],
        'startup' => ['enabled' => false],
    ],
];
