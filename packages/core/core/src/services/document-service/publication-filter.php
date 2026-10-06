<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService;

use Strapi\Core\Strapi;
use Strapi\Database\Query\Raw;
use Strapi\Utils\ContentTypes;

/**
 * Port of services/document-service/publication-filter.ts plus `buildPublicationFilterWhere` from
 * @strapi/utils publication-filter.ts (the knex subqueries become a {@see Raw} `id IN (subquery)`).
 */
final class PublicationFilter
{
    /**
     * @return array<string, mixed>|null a `where` fragment (`['id' => ['$in' => Raw]]`)
     */
    public static function getPublicationFilterCondition(Strapi $strapi, string $uid, string $mode, string $status): ?array
    {
        $model = $strapi->getModel($uid);

        if ($model === null || !ContentTypes::hasDraftAndPublish($model)) {
            return null;
        }

        $meta = $strapi->db()->metadata->get($uid);
        $sql = $strapi->db()->sql();
        $q = static fn (string $id): string => $sql->quoteIdentifier($id);

        $table = $q($meta['tableName']);
        $column = static fn (string $attr) => $q((string) ($meta['attributes'][$attr]['columnName'] ?? $attr));
        $idCol = $column('id');
        $docCol = $column('documentId');
        $pubCol = $column('publishedAt');
        $updatedCol = $column('updatedAt');
        $hasLocale = isset($meta['attributes']['locale']);
        $localeCol = $hasLocale ? $column('locale') : null;

        $pairOn = static function (string $a, string $b) use ($docCol, $localeCol): string {
            $parts = ["{$a}.{$docCol} = {$b}.{$docCol}"];
            if ($localeCol !== null) {
                $parts[] = "({$a}.{$localeCol} = {$b}.{$localeCol} OR ({$a}.{$localeCol} IS NULL AND {$b}.{$localeCol} IS NULL))";
            }

            return implode(' AND ', $parts);
        };
        $documentOn = static fn (string $a, string $b): string => "{$a}.{$docCol} = {$b}.{$docCol}";

        $empty = static fn (): array => ['id' => ['$in' => new Raw("SELECT {$idCol} FROM {$table} WHERE 1 = 0")]];
        $idIn = static fn (string $subquery): array => ['id' => ['$in' => new Raw($subquery)]];

        $d = $q('d');
        $p = $q('p');

        $draftsWhere = static fn (string $exists, string $on, string $extra = ''): string =>
            "SELECT {$d}.{$idCol} FROM {$table} AS {$d} WHERE {$d}.{$pubCol} IS NULL AND {$exists} (SELECT 1 FROM {$table} AS {$p} WHERE {$on} AND {$p}.{$pubCol} IS NOT NULL{$extra})";
        $publishedWhere = static fn (string $exists, string $on, string $extra = ''): string =>
            "SELECT {$p}.{$idCol} FROM {$table} AS {$p} WHERE {$p}.{$pubCol} IS NOT NULL AND {$exists} (SELECT 1 FROM {$table} AS {$d} WHERE {$on} AND {$d}.{$pubCol} IS NULL{$extra})";

        switch ($mode) {
            case 'never-published':
                if ($status === 'published') {
                    return $empty();
                }

                return $idIn($draftsWhere('NOT EXISTS', $pairOn($p, $d)));

            case 'has-published-version':
                if ($status === 'draft') {
                    return $idIn($draftsWhere('EXISTS', $pairOn($p, $d)));
                }

                return $idIn($publishedWhere('EXISTS', $pairOn($d, $p)));

            case 'modified':
                if ($status === 'draft') {
                    return $idIn($draftsWhere('EXISTS', $pairOn($p, $d), " AND {$d}.{$updatedCol} > {$p}.{$updatedCol}"));
                }

                return $idIn($publishedWhere('EXISTS', $pairOn($d, $p), " AND {$d}.{$updatedCol} > {$p}.{$updatedCol}"));

            case 'unmodified':
                if ($status === 'draft') {
                    return $idIn($draftsWhere('EXISTS', $pairOn($p, $d), " AND {$d}.{$updatedCol} <= {$p}.{$updatedCol}"));
                }

                return $idIn($publishedWhere('EXISTS', $pairOn($d, $p), " AND {$d}.{$updatedCol} <= {$p}.{$updatedCol}"));

            case 'never-published-document':
                if ($status === 'published') {
                    return $empty();
                }

                return $idIn($draftsWhere('NOT EXISTS', $documentOn($p, $d)));

            case 'has-published-version-document':
                if ($status === 'draft') {
                    return $idIn($draftsWhere('EXISTS', $documentOn($p, $d)));
                }

                return $idIn($publishedWhere('EXISTS', $documentOn($d, $p)));

            case 'published-without-draft':
                if ($status === 'draft') {
                    return $empty();
                }

                return $idIn($publishedWhere('NOT EXISTS', $pairOn($d, $p)));

            case 'published-with-draft':
                if ($status === 'draft') {
                    return $empty();
                }

                return $idIn($publishedWhere('EXISTS', $pairOn($d, $p)));

            default:
                return null;
        }
    }
}
