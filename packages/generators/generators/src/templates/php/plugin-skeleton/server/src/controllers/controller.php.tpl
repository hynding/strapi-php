<?php

declare(strict_types=1);

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

return static fn (Strapi $strapi): array => [
    'index' => static function (Context $ctx) use ($strapi): void {
        $ctx->setBody(
            $strapi
                ->plugin('{{ pluginId }}')
                // the name of the service file & the method.
                ->service('service')
                ->getWelcomeMessage(),
        );
    },
];
