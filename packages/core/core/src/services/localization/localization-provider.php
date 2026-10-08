<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Localization;

use Strapi\Types\Schema\Schema;

/**
 * What the i18n plugin registers on `strapi.localization` (upstream's `LocalizationProvider` in
 * services/localization.ts).
 */
interface LocalizationProvider
{
    /** @param Schema|array<string, mixed> $model */
    public function isLocalizedContentType(Schema|array $model): bool;

    public function getDefaultLocale(): ?string;

    /** @return list<mixed> */
    public function getLocales(): array;

    /** @return list<string> */
    public function getNestedPopulateOfNonLocalizedAttributes(string $modelUID): array;

    /**
     * @param Schema|array<string, mixed> $model
     * @return list<string>
     */
    public function getNonLocalizedAttributes(Schema|array $model): array;

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $relatedEntry
     * @param array{model: string} $options
     */
    public function fillNonLocalizedAttributes(array &$entry, array $relatedEntry, array $options): void;
}
