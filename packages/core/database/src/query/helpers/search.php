<?php

declare(strict_types=1);

namespace Strapi\Database\Query\Helpers;

use Strapi\Database\Query\QueryBuilder;
use Strapi\Database\Query\SqlBuilder;
use Strapi\Database\Utils\Types;

/** Port of packages/core/database/src/query/helpers/search.ts. */
final class Search
{
    public static function applySearch(SqlBuilder $sql, string $query, QueryBuilder $qb, string $uid): void
    {
        $db = $qb->db;
        $meta = $db->metadata->get($uid);
        $attributes = $meta['attributes'];

        $searchColumns = ['id'];

        foreach ($attributes as $attributeName => $attribute) {
            if (Types::isScalarAttribute($attribute) && Types::isString((string) $attribute['type']) && ($attribute['searchable'] ?? true) !== false) {
                $searchColumns[] = (string) $attributeName;
            }
        }

        if (is_numeric(trim($query))) {
            foreach ($attributes as $attributeName => $attribute) {
                if (Types::isScalarAttribute($attribute) && Types::isNumber((string) $attribute['type']) && ($attribute['searchable'] ?? true) !== false) {
                    $searchColumns[] = (string) $attributeName;
                }
            }
        }

        $pattern = '%' . self::escapeQuery($query, '*%\\') . '%';

        foreach ($searchColumns as $attr) {
            $columnName = Transform::toColumnName($meta, $attr);
            $aliased = $qb->aliasColumn($columnName);
            match ($db->dialect->client) {
                'postgres' => $sql->orWhereRaw('??::text ILIKE ?', [$aliased, $pattern]),
                'sqlite' => $sql->orWhereRaw("?? LIKE ? ESCAPE '\\'", [$aliased, $pattern]),
                'mysql' => $sql->orWhereRaw('?? LIKE ?', [$aliased, $pattern]),
                default => null,
            };
        }
    }

    public static function escapeQuery(string $query, string $charsToEscape, string $escapeChar = '\\'): string
    {
        $out = '';
        foreach (mb_str_split($query) as $char) {
            $out .= str_contains($charsToEscape, $char) ? $escapeChar . $char : $char;
        }

        return $out;
    }
}
