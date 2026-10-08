<?php

declare(strict_types=1);

use Strapi\Core\Strapi;
use StrapiPlugin\Announcements\Controllers\Announcement;

return [
    'announcement' => static fn (Strapi $strapi): Announcement => new Announcement($strapi),
];
