<?php

declare(strict_types=1);

use Strapi\Database\Lifecycles\Event;

return [
    'beforeUpdate' => static function (Event $event): void {
        // $ctx = \Strapi\Core\Core::instance()?->requestContext()->get();
    },
];
