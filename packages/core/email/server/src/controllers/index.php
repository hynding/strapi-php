<?php

declare(strict_types=1);

/** Port of server/src/controllers/index.ts. */

use Strapi\Core\Strapi;
use Strapi\Email\Controllers\Email;

return [
    'email' => static fn (Strapi $strapi): object => new Email($strapi),
];
