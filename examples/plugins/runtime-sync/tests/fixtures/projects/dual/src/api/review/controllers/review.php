<?php

declare(strict_types=1);

use Strapi\Core\Factories;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

return Factories::createCoreController('api::review.review', static fn (Strapi $strapi): array => [
    'find' => function (Context $ctx): mixed {
        return $this->find($ctx);
    },
]);
