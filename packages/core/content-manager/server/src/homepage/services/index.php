<?php

declare(strict_types=1);

/** Port of server/src/homepage/services/index.ts. */

use Strapi\ContentManager\Homepage\Services\Homepage;
use Strapi\Core\Strapi;

return [
    'homepage' => static fn (Strapi $strapi): object => Homepage::createHomepageService($strapi),
];
