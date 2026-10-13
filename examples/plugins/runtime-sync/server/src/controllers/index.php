<?php

declare(strict_types=1);

use Strapi\Core\Strapi;
use StrapiPlugin\RuntimeSync\Controllers\Status;

return [
    'status' => static fn (Strapi $strapi): Status => new Status($strapi),
];
