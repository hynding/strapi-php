<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n;

use Strapi\Core\Services\Localization\LocalizationProvider as LocalizationProviderContract;
use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Utils\Utils;
use Strapi\Types\Schema\Schema;

/**
 * Not an upstream file. Upstream core calls `strapi.plugin('i18n').service('content-types' |
 * 'locales')` directly; strapi-php's core reaches them through `strapi.localization`, on which
 * the plugin registers this provider (see {@see Register}). Every method delegates to the
 * plugin's services.
 */
final class LocalizationProvider implements LocalizationProviderContract
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function isLocalizedContentType(Schema|array $model): bool
    {
        return Utils::contentTypes($this->strapi)->isLocalizedContentType($model);
    }

    public function getDefaultLocale(): ?string
    {
        return Utils::locales($this->strapi)->getDefaultLocale();
    }

    public function getLocales(): array
    {
        return Utils::locales($this->strapi)->find();
    }

    public function getNestedPopulateOfNonLocalizedAttributes(string $modelUID): array
    {
        return Utils::contentTypes($this->strapi)->getNestedPopulateOfNonLocalizedAttributes($modelUID);
    }

    public function getNonLocalizedAttributes(Schema|array $model): array
    {
        return Utils::contentTypes($this->strapi)->getNonLocalizedAttributes($model);
    }

    public function fillNonLocalizedAttributes(array &$entry, array $relatedEntry, array $options): void
    {
        Utils::contentTypes($this->strapi)->fillNonLocalizedAttributes($entry, $relatedEntry, $options);
    }
}
