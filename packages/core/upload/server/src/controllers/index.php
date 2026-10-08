<?php

declare(strict_types=1);

/** Port of server/src/controllers/index.ts. */

use Strapi\Core\Strapi;
use Strapi\Upload\Controllers;

return [
    'admin-file' => static fn (Strapi $strapi): object => new Controllers\AdminFile($strapi),
    'admin-folder' => static fn (Strapi $strapi): object => new Controllers\AdminFolder($strapi),
    'admin-folder-file' => static fn (Strapi $strapi): object => new Controllers\AdminFolderFile($strapi),
    'admin-settings' => static fn (Strapi $strapi): object => new Controllers\AdminSettings($strapi),
    'admin-upload' => static fn (Strapi $strapi): object => new Controllers\AdminUpload($strapi),
    'content-api' => static fn (Strapi $strapi): object => new Controllers\ContentApi($strapi),
    'view-configuration' => static fn (Strapi $strapi): object => new Controllers\ViewConfiguration($strapi),
];
