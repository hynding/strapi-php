<?php

declare(strict_types=1);

namespace Strapi\Utils\Primitives;

/** Port of packages/core/utils/src/primitives/dates.ts. */
final class Dates
{
    /** Millisecond timestamp in base 36, unique enough for file/folder suffixes. */
    public static function timestampCode(?\DateTimeInterface $date = null): string
    {
        $ms = $date === null
            ? (int) floor(microtime(true) * 1000)
            : (int) $date->format('Uv');

        return base_convert((string) $ms, 10, 36);
    }
}
