<?php

declare(strict_types=1);

use Strapi\Core\Strapi;
use StrapiPlugin\RuntimeSync\Services\Preflight;
use StrapiPlugin\RuntimeSync\Services\Status;

return [
    'preflight' => static fn (Strapi $strapi): Preflight => new Preflight($strapi),
    'status' => static fn (Strapi $strapi): Status => new Status($strapi),
];
