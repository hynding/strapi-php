<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Transform\Relations\Transform;

use Strapi\Core\Services\DocumentService\Transform\Relations\Utils\I18n;
use Strapi\Core\Services\DocumentService\Transform\Relations\Utils\MapRelation;
use Strapi\Core\Strapi;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;

/**
 * Port of transform/relations/transform/default-locale.ts: in scenarios like Non i18n CT -> i18n CT,
 * relations can be connected to multiple locales; when the user does not provide the locale this
 * sets it to the default one.
 */
final class DefaultLocale
{
    /** @param array<string, mixed> $data */
    public static function setDefaultLocaleToRelations(Strapi $strapi, array $data, string $uid): mixed
    {
        // I18n CT -> anything will already have a locale set (source locale)
        if (I18n::isLocalizedContentType($strapi, $uid)) {
            return $data;
        }

        // Store the default locale to avoid multiple calls
        $defaultLocale = null;

        return MapRelation::traverseEntityRelations(
            static function (VisitorOptions $options, VisitorUtils $utils) use ($strapi, &$defaultLocale): void {
                // Assign default locale on long hand expressed relations: { documentId } -> { documentId, locale }
                $relation = MapRelation::mapRelation(static function (mixed $relation) use ($strapi, &$defaultLocale): mixed {
                    if (!is_array($relation) || empty($relation['documentId']) || !empty($relation['locale'])) {
                        return $relation;
                    }

                    // Set default locale if not provided
                    if ($defaultLocale === null) {
                        $defaultLocale = I18n::getDefaultLocale($strapi);
                    }

                    // Assign default locale to the positional argument
                    if (is_array($relation['position'] ?? null) && empty($relation['position']['locale'])) {
                        $relation['position']['locale'] = $defaultLocale;
                    }

                    return [...$relation, 'locale' => $defaultLocale];
                }, $options->value);

                $utils->set($options->key, $relation);
            },
            ['schema' => $strapi->getModel($uid), 'getModel' => static fn (string $u) => $strapi->getModel($u)],
            $data,
        );
    }
}
