<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Transform\Relations\Utils;

use Strapi\Core\Strapi;

/** Port of transform/relations/utils/i18n.ts. */
final class I18n
{
    public static function isLocalizedContentType(Strapi $strapi, string $uid): bool
    {
        return $strapi->localization()->isLocalizedContentType($strapi->getModel($uid));
    }

    public static function getDefaultLocale(Strapi $strapi): ?string
    {
        return $strapi->localization()->getDefaultLocale();
    }

    /**
     * @param array{locale?: string|null} $relation
     * @param array{targetUid: string, sourceUid: string, sourceLocale?: string|null} $opts
     */
    public static function getRelationTargetLocale(Strapi $strapi, array $relation, array $opts): ?string
    {
        $targetLocale = !empty($relation['locale']) ? (string) $relation['locale'] : ($opts['sourceLocale'] ?? null);

        $isTargetLocalized = self::isLocalizedContentType($strapi, $opts['targetUid']);
        $isSourceLocalized = self::isLocalizedContentType($strapi, $opts['sourceUid']);

        // Both source and target locales should match
        if ($isSourceLocalized && $isTargetLocalized) {
            return $opts['sourceLocale'] ?? null;
        }

        if ($isTargetLocalized) {
            return $targetLocale;
        }

        return null;
    }
}
