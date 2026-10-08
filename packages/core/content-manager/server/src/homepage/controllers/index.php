<?php

declare(strict_types=1);

/** Port of server/src/homepage/controllers/index.ts. */

use Strapi\ContentManager\Homepage\Controllers\Homepage;
use Strapi\Core\Strapi;

return [
    'homepage' => static fn (Strapi $strapi): object => Homepage::createHomepageController($strapi),
];
