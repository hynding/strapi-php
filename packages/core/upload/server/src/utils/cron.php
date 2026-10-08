<?php

declare(strict_types=1);

namespace Strapi\Upload\Utils;

/** Port of server/src/utils/cron.ts. */
final class Cron
{
    /** `${seconds} ${minutes} ${hours} * * ${day}` (local time, as `Date#getHours()` and co.) */
    public static function getWeeklyCronScheduleAt(\DateTimeInterface $date): string
    {
        return sprintf('%d %d %d * * %d', (int) $date->format('s'), (int) $date->format('i'), (int) $date->format('G'), (int) $date->format('w'));
    }
}
