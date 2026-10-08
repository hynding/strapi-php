<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Strapi\Core\Services\Localization\LocalizationProvider;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;

/**
 * Port of packages/core/core/src/services/localization.ts: the localization capability used by core.
 *
 * Without a registered provider (the i18n plugin is not ported yet) upstream reports no content type
 * as localized and a `null` default locale. This port keeps that contract but, because the
 * `pluginOptions.i18n.localized` schemas of the example project must still get a `locale` column
 * value, {@see self::isLocalizedContentType()} reads the schema flag and the default locale falls
 * back to `'en'` (the i18n plugin's own default) when no provider is registered.
 *
 * A provider implements {@see LocalizationProvider}.
 */
final class Localization
{
    private ?LocalizationProvider $provider = null;

    public static function createLocalizationService(): self
    {
        return new self();
    }

    public function register(LocalizationProvider $localizationProvider): void
    {
        if ($this->provider !== null) {
            throw new \RuntimeException('A localization provider is already registered for this application.');
        }

        $this->provider = $localizationProvider;
    }

    public function isEnabled(): bool
    {
        return $this->provider !== null;
    }

    /** @param Schema|array<string, mixed>|null $model */
    public function isLocalizedContentType(Schema|array|null $model): bool
    {
        if ($model === null) {
            return false;
        }
        if ($this->provider !== null) {
            return $this->provider->isLocalizedContentType($model);
        }

        return ContentTypes::getDoesPluginOptionHaveValue($model, 'i18n', 'localized', true);
    }

    public function getDefaultLocale(): ?string
    {
        if ($this->provider !== null) {
            return $this->provider->getDefaultLocale();
        }

        return 'en';
    }

    /** @return list<mixed> */
    public function getLocales(): array
    {
        if ($this->provider !== null) {
            return $this->provider->getLocales();
        }

        return [];
    }

    /** @return list<string> */
    public function getNestedPopulateOfNonLocalizedAttributes(string $modelUID): array
    {
        return $this->provider !== null ? $this->provider->getNestedPopulateOfNonLocalizedAttributes($modelUID) : [];
    }

    /**
     * @param Schema|array<string, mixed> $model
     * @return list<string>
     */
    public function getNonLocalizedAttributes(Schema|array $model): array
    {
        return $this->provider !== null ? $this->provider->getNonLocalizedAttributes($model) : [];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $relatedEntry
     * @param array{model: string} $options
     */
    public function fillNonLocalizedAttributes(array &$entry, array $relatedEntry, array $options): void
    {
        if ($this->provider !== null) {
            $this->provider->fillNonLocalizedAttributes($entry, $relatedEntry, $options);
        }
    }
}
