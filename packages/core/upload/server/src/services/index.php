<?php

declare(strict_types=1);

/** Port of server/src/services/index.ts. */

use Strapi\Core\Strapi;
use Strapi\Upload\Services;

return [
    'provider' => static fn (Strapi $strapi): object => new Services\Provider($strapi),
    'upload' => static fn (Strapi $strapi): object => new Services\Upload($strapi),
    'folder' => static fn (Strapi $strapi): object => new Services\Folder($strapi),
    'file' => static fn (Strapi $strapi): object => new Services\File($strapi),
    'weeklyMetrics' => static fn (Strapi $strapi): object => new Services\WeeklyMetrics($strapi),
    'metrics' => static fn (Strapi $strapi): object => new Services\Metrics($strapi),
    'image-manipulation' => static fn (Strapi $strapi): object => new Services\ImageManipulation($strapi),
    'api-upload-folder' => static fn (Strapi $strapi): object => new Services\ApiUploadFolder($strapi),
    'extensions' => static fn (Strapi $strapi): object => new Services\Extensions\Extensions($strapi),
    'aiMetadata' => static fn (Strapi $strapi): object => new Services\AiMetadata($strapi),
    'aiMetadataJobs' => static fn (Strapi $strapi): object => new Services\AiMetadataJobs($strapi),
    'aiMetadataProvider' => static fn (Strapi $strapi): object => new Services\AiMetadataProvider($strapi),
];
