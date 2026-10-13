<?php

declare(strict_types=1);

return static fn (): array => [
    'serveAdminPanel' => false,
    'auth' => ['secret' => 'runtime-sync'],
    'apiToken' => ['salt' => 'runtime-sync'],
    'transfer' => ['token' => ['salt' => 'runtime-sync']],
    'secrets' => ['encryptionKey' => 'runtime-sync'],
];
