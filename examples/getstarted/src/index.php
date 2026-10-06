<?php

declare(strict_types=1);

use Strapi\Core\Strapi;

return [
    /**
     * A register function that runs before your application is initialized.
     * This gives you an opportunity to extend code.
     */
    'register' => static function (Strapi $strapi): void {
        // The kitchensink schema uses the `plugin::color-picker.color` custom field. Upstream registers it
        // from @strapi/plugin-color-picker, which is not ported yet, so the project declares it here
        // (same options as the plugin's server/register.ts).
        $strapi->customFields()->register([
            'name' => 'color',
            'plugin' => 'color-picker',
            'type' => 'string',
            'inputSize' => ['default' => 4, 'isResizable' => false],
        ]);

        // Custom Content API params: add extra query and body keys to content-api routes. When
        // api.rest.strictParams is on, only core params (and params on the route's request schema)
        // are allowed. A validator is `callable(mixed): mixed` (return the parsed value, throw to reject).

        // Custom query param: e.g. GET /api/articles?extraParam=coffee
        $strapi->contentAPI()->addQueryParams([
            'extraParam' => [
                'schema' => static function (mixed $value): string {
                    if (!is_string($value) || strlen($value) > 200) {
                        throw new \InvalidArgumentException('extraParam must be a string of at most 200 characters');
                    }

                    return $value;
                },
                'matchRoute' => static fn (array $route): bool => str_contains((string) $route['path'], 'articles'),
            ],
        ]);

        // Custom body param: e.g. POST /api/articles with body { data: {...}, clientMutationId: 'abc-123' }
        $strapi->contentAPI()->addInputParams([
            'clientMutationId' => [
                'schema' => static function (mixed $value): string {
                    if (!is_string($value) || strlen($value) > 100) {
                        throw new \InvalidArgumentException('clientMutationId must be a string of at most 100 characters');
                    }

                    return $value;
                },
            ],
        ]);
    },

    /**
     * A bootstrap function that runs before your application gets started.
     * This gives you an opportunity to set up your data model, run jobs, or perform some special logic.
     */
    'bootstrap' => static function (Strapi $strapi): void {
    },

    /**
     * A destroy function that runs before your application gets shut down.
     */
    'destroy' => static function (Strapi $strapi): void {
    },
];
