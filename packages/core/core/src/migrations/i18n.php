<?php

declare(strict_types=1);

namespace Strapi\Core\Migrations;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/migrations/i18n.ts.
 *
 * @phpstan-import-type Input from Migrations
 */
final class I18n
{
    /** @param Input $input */
    public static function enable(Strapi $strapi, array $input): void
    {
        $oldContentTypes = $input['oldContentTypes'] ?? null;
        if (!is_array($oldContentTypes) || $oldContentTypes === []) {
            return;
        }
        $localization = $strapi->localization();

        foreach ($input['contentTypes'] as $uid => $contentType) {
            $uid = (string) $uid;
            if (!isset($oldContentTypes[$uid])) {
                continue;
            }

            if (!$localization->isLocalizedContentType($oldContentTypes[$uid]) && $localization->isLocalizedContentType($contentType)) {
                $defaultLocale = $localization->getDefaultLocale();

                $strapi->db()->query($uid)->updateMany(['where' => ['locale' => null], 'data' => ['locale' => $defaultLocale]]);
            }
        }
    }

    /** @param Input $input */
    public static function disable(Strapi $strapi, array $input): void
    {
        $oldContentTypes = $input['oldContentTypes'] ?? null;
        if (!is_array($oldContentTypes) || $oldContentTypes === []) {
            return;
        }
        $localization = $strapi->localization();

        foreach ($input['contentTypes'] as $uid => $contentType) {
            $uid = (string) $uid;
            if (!isset($oldContentTypes[$uid])) {
                continue;
            }

            // if i18N is disabled remove non default locales before sync
            if ($localization->isLocalizedContentType($oldContentTypes[$uid]) && !$localization->isLocalizedContentType($contentType)) {
                $defaultLocale = $localization->getDefaultLocale();

                // `$ne: null` would match every localized row and delete them all
                if ($defaultLocale === null) {
                    throw new \RuntimeException("Cannot disable localization for \"{$uid}\": no default locale is set, so non-default rows cannot be identified.");
                }

                // Delete all entities that are not in the default locale
                $strapi->db()->query($uid)->deleteMany(['where' => ['locale' => ['$ne' => $defaultLocale]]]);
                // Set locale to null for the rest
                $strapi->db()->query($uid)->updateMany(['where' => ['locale' => ['$eq' => $defaultLocale]], 'data' => ['locale' => null]]);
            }
        }
    }
}
