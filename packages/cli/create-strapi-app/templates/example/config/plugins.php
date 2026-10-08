<?php

declare(strict_types=1);

use Strapi\Utils\EnvHelper;

$allowedMediaTypes = [
    'image/*',
    'video/*',
    'audio/*',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.*',
    'text/plain',
    'text/csv',
];

$deniedTypes = [
    'image/svg+xml',
    'application/vnd.microsoft.portable-executable',
    'application/x-msdownload',
    'application/x-msdos-program',
    'application/x-executable',
    'application/x-dosexec',
    'application/x-sh',
    'text/x-shellscript',
    'application/x-mach-binary',
];

return static fn (EnvHelper $env): array => [
    'users-permissions' => [
        'config' => [
            'jwtManagement' => 'refresh',
            'sessions' => [
                'httpOnly' => true,
            ],
        ],
    ],
    'upload' => [
        'config' => [
            'security' => [
                'allowedTypes' => $allowedMediaTypes,
                'deniedTypes' => $deniedTypes,
            ],
        ],
    ],
];
