<?php

declare(strict_types=1);

namespace Strapi\Types\Utils;

/** Small helpers every package may use without pulling strapi/utils. */
final class Str
{
    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $value)));
    }

    public static function camel(string $value): string
    {
        return lcfirst(self::studly($value));
    }

    public static function kebab(string $value): string
    {
        $value = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $value);

        return strtolower(str_replace(['_', ' '], '-', $value));
    }

    public static function snake(string $value): string
    {
        $value = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value);

        return strtolower(str_replace(['-', ' '], '_', $value));
    }
}
