<?php

declare(strict_types=1);

use Strapi\Email\Routes\Validation\EmailRouteValidator;

/**
 * Port of server/src/routes/content-api.ts. Upstream builds the routes lazily with
 * `createContentApiRoutesFactory`; this file returns the router (`type: 'content-api'`).
 */
return (static function (): array {
    $validator = new EmailRouteValidator();

    return [
        'type' => 'content-api',
        'routes' => [
            [
                'method' => 'POST',
                'path' => '/',
                'handler' => 'email.send',
                'request' => [
                    'body' => ['application/json' => $validator->sendEmailInput()],
                ],
                'response' => $validator->emailResponse(),
            ],
        ],
    ];
})();
