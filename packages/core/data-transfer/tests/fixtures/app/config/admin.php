<?php

declare(strict_types=1);

return static fn (): array => [
    'auth' => ['secret' => 'dts-admin-secret'],
    'apiToken' => ['salt' => 'dts-api-token-salt'],
    'transfer' => ['token' => ['salt' => 'dts-transfer-token-salt']],
    'secrets' => ['encryptionKey' => 'dts-encryption-key'],
];
