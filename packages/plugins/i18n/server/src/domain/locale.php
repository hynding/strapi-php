<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Domain;

/** Port of server/src/domain/locale.ts. */
final class Locale
{
    /**
     * @param array<string, mixed> $locale
     * @return array<string, mixed>
     */
    public static function formatLocale(array $locale): array
    {
        return [
            ...$locale,
            'name' => ($locale['name'] ?? null) ?: null,
        ];
    }
}
