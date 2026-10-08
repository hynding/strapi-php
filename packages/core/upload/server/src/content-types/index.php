<?php

declare(strict_types=1);

// Port of server/src/content-types/index.ts
return [
    'file' => require __DIR__ . '/file.php',
    'folder' => require __DIR__ . '/folder.php',
];
