<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

use Strapi\Types\Modules\Config\Config;

/** Port of packages/core/core/src/utils/open-browser.ts — a no-op under PHP (no `open` package). */
final class OpenBrowser
{
    public static function openBrowser(Config $config): void
    {
        // intentionally a no-op: opening a browser from the server process is a Node CLI nicety
    }
}
