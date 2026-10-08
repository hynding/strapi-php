<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Utils;

use Strapi\Core\Strapi;
use Strapi\Database\Query\SqlBuilder;

/**
 * Not an upstream file: the few raw-table helpers (`knex(table).select('*').whereIn(...)`,
 * `batchInsert`) shared by the relation sync utilities.
 */
final class RelationSyncHelpers
{
    public static function sql(Strapi $strapi): SqlBuilder
    {
        return $strapi->db()->sql();
    }

    /**
     * @param list<mixed> $ids
     * @param list<mixed> $notInIds
     * @return list<array<string, mixed>>
     */
    public static function selectWhereIn(Strapi $strapi, string $table, string $column, array $ids, ?string $notInColumn = null, array $notInIds = []): array
    {
        if ($ids === []) {
            return [];
        }
        $qb = self::sql($strapi)->from($table)->select('*')->whereIn($column, array_values($ids));
        if ($notInColumn !== null && $notInIds !== []) {
            $qb->whereNotIn($notInColumn, array_values($notInIds));
        }
        $rows = $qb->run();

        return is_array($rows) ? $rows : [];
    }

    /** @param list<array<string, mixed>> $rows */
    public static function batchInsert(Strapi $strapi, string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $batchSize = $strapi->db()->dialect->getBatchInsertSize();
        foreach (array_chunk($rows, $batchSize) as $chunk) {
            self::sql($strapi)->from($table)->insert(array_values($chunk))->run();
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function omitId(array $row, string $idColumn = 'id'): array
    {
        unset($row[$idColumn]);

        return $row;
    }
}
