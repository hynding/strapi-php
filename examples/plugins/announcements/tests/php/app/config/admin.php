<?php

declare(strict_types=1);

return static fn (): array => [
    'auth' => ['secret' => 'conformance'],
    'apiToken' => ['salt' => 'conformance'],
    'transfer' => ['token' => ['salt' => 'conformance']],
];
