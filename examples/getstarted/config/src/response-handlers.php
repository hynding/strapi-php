<?php

declare(strict_types=1);

use Strapi\Types\Core\Context;

return [
    404 => static function (Context $ctx): void {
        // $ctx->notFound('My custom message 404');
    },
];
