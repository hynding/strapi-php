<?php

declare(strict_types=1);

/** Mirrors routes/index.js: the route spec is JSON, read as-is by both runtimes. */
return [
    'admin' => json_decode((string) file_get_contents(__DIR__ . '/admin.json'), true, flags: JSON_THROW_ON_ERROR),
];
