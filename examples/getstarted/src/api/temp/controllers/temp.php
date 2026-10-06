<?php

declare(strict_types=1);

/**
 * temp controller: the core controller plus a custom `ping` action
 */

use Strapi\Core\Factories;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

return Factories::createCoreController('api::temp.temp', static fn (Strapi $strapi): array => [
    'ping' => function (Context $ctx): array {
        return ['data' => ['pong' => true, 'uid' => $this->uid]];
    },
]);
