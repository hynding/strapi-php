<?php

declare(strict_types=1);

namespace Strapi\Core\Utils\UpdateNotifier;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/utils/update-notifier/index.ts — a no-op: the npm registry
 * check and Configstore cache do not apply to the Composer distribution. `scripts/strapi-release-watch.php`
 * covers upstream release tracking.
 */
final class UpdateNotifier
{
    public static function createUpdateNotifier(Strapi $strapi): void
    {
    }
}
