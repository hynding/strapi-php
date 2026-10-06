<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

use Strapi\Core\Strapi;

/** Port of packages/core/core/src/utils/is-initialized.ts. */
final class IsInitialized
{
    /** Test if the strapi application is considered as initialized (1st admin user has been created). */
    public static function isInitialized(Strapi $strapi): bool
    {
        try {
            if (!$strapi->has('admin') || empty($strapi->get('admin'))) {
                return true;
            }

            // test if there is at least one admin
            $anyAdministrator = $strapi->db()->query('admin::user')->findOne(['select' => ['id']]);

            return $anyAdministrator !== null;
        } catch (\Throwable $err) {
            $strapi->stopWithError($err);
        }
    }
}
