<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops\Utils;

/** Port of src/plops/utils/get-formatted-date.ts: local time as `YYYY.MM.DDTHH.mm.ss`. */
final class GetFormattedDate
{
    public static function getFormattedDate(?\DateTimeInterface $date = null): string
    {
        $date ??= new \DateTimeImmutable();

        return $date->format('Y.m.d\TH.i.s');
    }
}
