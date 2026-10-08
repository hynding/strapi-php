<?php

declare(strict_types=1);

return [
    'announcement' => [
        'schema' => json_decode((string) file_get_contents(__DIR__ . '/announcement/schema.json'), true, flags: JSON_THROW_ON_ERROR),
    ],
];
