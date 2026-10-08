<?php

declare(strict_types=1);

/**
 * Port of server/src/middlewares/index.ts (`export { isDevelopmentMode }`). Registered as the
 * plugin middleware `plugin::content-type-builder.isDevelopmentMode`; like upstream's export, the
 * registered value is the `(ctx, next)` function itself, returned by the factory below.
 */

use Strapi\ContentTypeBuilder\Middlewares\IsDevelopmentMode;
use Strapi\Core\Strapi;

return [
    'isDevelopmentMode' => static fn (array $config, Strapi $strapi): callable => new IsDevelopmentMode($strapi),
];
