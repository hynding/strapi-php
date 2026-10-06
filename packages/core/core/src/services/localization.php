<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

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
 * A provider is any object with `isLocalizedContentType`, `getDefaultLocale`, `getLocales`,
 * `getNestedPopulateOfNonLocalizedAttributes`, `getNonLocalizedAttributes`, `fillNonLocalizedAttributes`.
 */
final class Localization
{
    private const PROVIDER_METHODS = [
        'isLocalizedContentType', 'getDefaultLocale', 'getLocales',
        'getNestedPopulateOfNonLocalizedAttributes', 'getNonLocalizedAttributes', 'fillNonLocalizedAttributes',
    ];

    private ?object $provider = null;

    public static function createLocalizationService(): self
    {
        return new self();
    }

    public function register(object $localizationProvider): void
    {
        if ($this->provider !== null) {
            throw new \RuntimeException('A localization provider is already registered for this application.');
        }

        // Fail at registration rather than on first use
        foreach (self::PROVIDER_METHODS as $name) {
            if (!method_exists($localizationProvider, $name)) {
                throw new \RuntimeException("Localization provider is missing \"{$name}\"");
            }
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
            return (bool) $this->provider->isLocalizedContentType($model);
        }

        return ContentTypes::getDoesPluginOptionHaveValue($model, 'i18n', 'localized', true);
    }

    public function getDefaultLocale(): ?string
    {
        if ($this->provider !== null) {
            $locale = $this->provider->getDefaultLocale();

            return is_string($locale) ? $locale : null;
        }

        return 'en';
    }

    /** @return list<mixed> */
    public function getLocales(): array
    {
        if ($this->provider !== null) {
            return (array) $this->provider->getLocales();
        }

        return [];
    }

    /** @return list<string> */
    public function getNestedPopulateOfNonLocalizedAttributes(string $modelUID): array
    {
        return $this->provider !== null ? (array) $this->provider->getNestedPopulateOfNonLocalizedAttributes($modelUID) : [];
    }

    /**
     * @param Schema|array<string, mixed> $model
     * @return list<string>
     */
    public function getNonLocalizedAttributes(Schema|array $model): array
    {
        return $this->provider !== null ? (array) $this->provider->getNonLocalizedAttributes($model) : [];
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
