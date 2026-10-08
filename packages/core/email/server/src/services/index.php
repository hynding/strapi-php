<?php

declare(strict_types=1);

/** Port of server/src/services/index.ts. */

use Strapi\Core\Strapi;
use Strapi\Email\Services\Email;

return [
    'email' => static fn (Strapi $strapi): object => new Email($strapi),
];
