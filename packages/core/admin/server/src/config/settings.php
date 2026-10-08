<?php

declare(strict_types=1);

// Port of server/src/config/settings.ts (`export const forgotPassword = { emailTemplate }`)

return [
    'forgotPassword' => [
        'emailTemplate' => require __DIR__ . '/email-templates/forgot-password.php',
    ],
];
