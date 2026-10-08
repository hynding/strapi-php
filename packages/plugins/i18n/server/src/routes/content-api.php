<?php

declare(strict_types=1);

use Strapi\Plugin\I18n\Routes\Validation\I18nLocaleRouteValidator;

/**
 * Port of server/src/routes/content-api.ts. Upstream builds the routes lazily with
 * `createContentApiRoutesFactory`; this file returns the router (`type: 'content-api'`).
 */
return (static function (): array {
    $validator = new I18nLocaleRouteValidator();

    return [
        'type' => 'content-api',
        'routes' => [
            [
                'method' => 'GET',
                'path' => '/locales',
                'handler' => 'locales.listLocales',
                'response' => $validator->locales(),
            ],
        ],
    ];
})();
