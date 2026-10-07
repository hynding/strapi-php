<?php

declare(strict_types=1);

$load = static fn (string $file): array => json_decode((string) file_get_contents(__DIR__ . "/{$file}"), true, flags: JSON_THROW_ON_ERROR);

return [
    'content-api' => $load('content-api.json'),
    'admin' => $load('admin.json'),
];
