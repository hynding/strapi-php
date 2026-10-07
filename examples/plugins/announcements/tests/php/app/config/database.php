<?php

declare(strict_types=1);

return static fn (): array => [
    'connection' => [
        'client' => 'sqlite',
        'connection' => ['filename' => ':memory:'],
        'useNullAsDefault' => true,
    ],
];
