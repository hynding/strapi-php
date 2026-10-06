<?php

declare(strict_types=1);

use Strapi\Types\Core\Context;

return static fn (): callable => static function (Context $ctx, callable $next): void {
    $next();
};
