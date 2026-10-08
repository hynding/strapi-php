<?php

declare(strict_types=1);

// Port of server/src/content-types/locale/index.ts (the schema stays in schema.json)
return [
    'schema' => json_decode((string) file_get_contents(__DIR__ . '/schema.json'), true, 512, JSON_THROW_ON_ERROR),
];
