<?php

declare(strict_types=1);

// create-strapi-app templates/vanilla/config/plugins.ts
return static fn (): array => [
    'users-permissions' => [
        'config' => [
            'jwtManagement' => 'refresh',
            'sessions' => ['httpOnly' => true],
        ],
    ],
    // Not in the vanilla template. Upstream's default sendmail provider delivers in the background
    // while the Node process keeps serving requests (and the suites mock `send`); here delivery is
    // synchronous in the one worker, and remote MX hosts on port 25 time out (60 s each) in CI
    // sandboxes. nodemailer's jsonTransport builds each message and sends nothing.
    'email' => [
        'config' => [
            'provider' => 'nodemailer',
            'providerOptions' => ['jsonTransport' => true],
        ],
    ],
    'upload' => [
        'config' => [
            'security' => [
                'allowedTypes' => [
                    'image/*',
                    'video/*',
                    'audio/*',
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.*',
                    'text/plain',
                    'text/csv',
                ],
                'deniedTypes' => [
                    'image/svg+xml',
                    'application/vnd.microsoft.portable-executable',
                    'application/x-msdownload',
                    'application/x-msdos-program',
                    'application/x-executable',
                    'application/x-dosexec',
                    'application/x-sh',
                    'text/x-shellscript',
                    'application/x-mach-binary',
                ],
            ],
        ],
    ],
];
