<?php

declare(strict_types=1);

/** Port of server/src/services/index.ts. */

use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Services;

return [
    'permissions' => static fn (Strapi $strapi): object => new Services\Permissions($strapi),
    'metrics' => static fn (Strapi $strapi): object => new Services\Metrics($strapi),
    'localizations' => static fn (Strapi $strapi): object => new Services\Localizations($strapi),
    'locales' => static fn (Strapi $strapi): object => new Services\Locales($strapi),
    'sanitize' => static fn (Strapi $strapi): object => new Services\Sanitize\Sanitize($strapi),
    'iso-locales' => static fn (Strapi $strapi): object => new Services\IsoLocales(),
    'content-types' => static fn (Strapi $strapi): object => new Services\ContentTypes($strapi),
    'ai-localizations' => static fn (Strapi $strapi): object => new Services\AiLocalizations($strapi),
    'ai-translations' => static fn (Strapi $strapi): object => new Services\AiTranslations($strapi),
    'ai-localization-jobs' => static fn (Strapi $strapi): object => new Services\AiLocalizationJobs($strapi),
    'settings' => static fn (Strapi $strapi): object => new Services\Settings($strapi),
    'fill-from-locale' => static fn (Strapi $strapi): object => new Services\FillFromLocale($strapi),
];
