<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

/** Port of packages/core/core/src/utils/ee.ts: the licence module (ee/*) is not ported, so EE is always off. */
final class Ee
{
    public const IS_EE = false;

    public static function isEE(): bool
    {
        return false;
    }
}
