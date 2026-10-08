<?php

declare(strict_types=1);

/** Port of server/src/middlewares/index.ts (`export { routing }`). */

use Strapi\ContentManager\Middlewares\Routing;

return [
    'routing' => Routing::class,
];
