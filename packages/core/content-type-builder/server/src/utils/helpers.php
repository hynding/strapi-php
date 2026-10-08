<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Utils;

/** Port of server/src/utils/helpers.ts. */
final class Helpers
{
    public static function escapeNewlines(?string $content = '', string $placeholder = "\n"): string
    {
        return (string) preg_replace('/[\r\n]+/', $placeholder, $content ?? '');
    }

    /**
     * Trims every string of a (nested) array; other values are returned as is.
     */
    public static function deepTrimObject(mixed $attribute): mixed
    {
        if ($attribute === null) {
            // typeof null is 'object' upstream: Object.entries(null) throws
            throw new \TypeError('Cannot convert undefined or null to object');
        }

        if (is_array($attribute)) {
            return array_map(self::deepTrimObject(...), $attribute);
        }

        // String.prototype.trim(): ASCII and Unicode white space, line terminators and the BOM
        return is_string($attribute) ? (string) preg_replace('/^[\s\p{Zs}\x{FEFF}\x{2028}\x{2029}]+|[\s\p{Zs}\x{FEFF}\x{2028}\x{2029}]+$/u', '', $attribute) : $attribute;
    }
}
