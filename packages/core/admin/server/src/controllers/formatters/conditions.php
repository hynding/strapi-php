<?php

declare(strict_types=1);

namespace Strapi\Admin\Controllers\Formatters;

/** Port of server/src/controllers/formatters/conditions.ts. */
final class Conditions
{
    /** visible fields for the API */
    public const array PUBLIC_FIELDS = ['id', 'displayName', 'category'];

    /**
     * `map(pick(publicFields))`
     *
     * @param list<array<string, mixed>> $conditions
     * @return list<array<string, mixed>>
     */
    public static function formatConditions(array $conditions): array
    {
        return array_values(array_map(static function (array $condition): array {
            $out = [];
            foreach (self::PUBLIC_FIELDS as $field) {
                if (array_key_exists($field, $condition)) {
                    $out[$field] = $condition[$field];
                }
            }

            return $out;
        }, $conditions));
    }
}
