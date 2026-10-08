<?php

declare(strict_types=1);

/** Port of server/src/controllers/index.ts. */

use Strapi\Core\Strapi;
use Strapi\Plugin\Documentation\Controllers\Documentation;

return [
    'documentation' => static fn (Strapi $strapi): Documentation => new Documentation($strapi),
];
