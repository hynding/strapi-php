<?php

declare(strict_types=1);

/** Port of server/src/services/index.ts. */

use Strapi\Core\Strapi;
use Strapi\Plugin\Documentation\Services\Documentation;
use Strapi\Plugin\Documentation\Services\Override;

return [
    'documentation' => static fn (Strapi $strapi): Documentation => new Documentation($strapi),
    'override' => static fn (Strapi $strapi): Override => new Override($strapi),
];
