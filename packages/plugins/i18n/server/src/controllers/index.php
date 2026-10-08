<?php

declare(strict_types=1);

/** Port of server/src/controllers/index.ts. */

use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Controllers;

return [
    'locales' => static fn (Strapi $strapi): object => new Controllers\Locales($strapi),
    'iso-locales' => static fn (Strapi $strapi): object => new Controllers\IsoLocales($strapi),
    'content-types' => static fn (Strapi $strapi): object => new Controllers\ContentTypes($strapi),
    'settings' => static fn (Strapi $strapi): object => new Controllers\Settings($strapi),
    'ai-localization-jobs' => static fn (Strapi $strapi): object => new Controllers\AiLocalizationJobs($strapi),
];
