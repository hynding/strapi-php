<?php

declare(strict_types=1);

use Strapi\Database\Lifecycles\Event;

return [
    'beforeCreate' => static function (Event $event): void {
        $event->params['data']['title'] = trim((string) $event->params['data']['title']);
    },
];
