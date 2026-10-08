<?php

declare(strict_types=1);

/** Port of server/src/config/index.ts. */

return [
    'forgotPassword' => [
        'emailTemplate' => require __DIR__ . '/email-templates/forgot-password.php',
    ],
];
