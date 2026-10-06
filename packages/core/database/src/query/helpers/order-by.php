<?php

declare(strict_types=1);

namespace Strapi\Database\Query\Helpers;

use Strapi\Database\Database;
use Strapi\Database\Query\QueryBuilder;
use Strapi\Database\Query\Raw;
use Strapi\Database\Query\SqlBuilder;
use Strapi\Database\Utils\Types;

/**
 * Port of packages/core/database/src/query/helpers/order-by.ts.
 *
 * @phpstan-type OrderByValue array{column: string, order?: string|null}|array{rawExpression: 'status', isI18n: bool, order?: string|null}
 */
final class OrderBy
{
    public const COL_STRAPI_ROW_NUMBER = '__strapi_row_number';
    public const COL_STRAPI_ORDER_BY_PREFIX = '__strapi_order_by';

    /**
     * Builds a SQL CASE expression that maps each row to a numeric status rank:
     * 0 = draft/created, 1 = modified, 2 = published.
     */
    public static function buildStatusSortExpression(SqlBuilder $sql, string $tableName, string $tableAlias, bool $isI18n = false): Raw
    {
        $q = $sql->quoteIdentifier(...);
        $table = $q($tableName);
        $alias = $q($tableAlias);
        $localeCondition = $isI18n ? " AND sub.locale = {$alias}.locale" : '';

        return new Raw(
            "CASE WHEN NOT EXISTS(SELECT 1 FROM {$table} sub WHERE sub.document_id = {$alias}.document_id AND sub.published_at IS NOT NULL{$localeCondition}) THEN 0"
            . " WHEN {$alias}.updated_at > (SELECT MAX(sub.updated_at) FROM {$table} sub WHERE sub.document_id = {$alias}.document_id AND sub.published_at IS NOT NULL{$localeCondition}) THEN 1 ELSE 2 END",
        );
    }

    /**
     * @param OrderByValue $entry
     *
     * @return array{column: string|Raw, order?: string|null}
     */
    public static function toSqlOrderByDescriptor(SqlBuilder $sql, string $tableName, string $rootTableAlias, array $entry): array
    {
        if (isset($entry['rawExpression'])) {
            return ['column' => self::buildStatusSortExpression($sql, $tableName, $rootTableAlias, $entry['isI18n'] ?? false), 'order' => $entry['order'] ?? null];
        }

        return ['column' => $entry['column'], 'order' => $entry['order'] ?? null];
    }

    /**
     * @param string|array<string, string>|list<mixed> $orderBy
     *
     * @return list<OrderByValue>
     */
    public static function processOrderBy(mixed $orderBy, QueryBuilder $qb, string $uid, ?string $alias = null): array
    {
        $db = $qb->db;
        $meta = $db->metadata->get($uid);
        $attributes = $meta['attributes'];

        if (is_string($orderBy)) {
            if ($orderBy === 'status') {
                if (!isset($attributes['publishedAt']) || !isset($attributes['documentId'])) {
                    throw new \InvalidArgumentException("Cannot order by status on model {$uid}: missing publishedAt or documentId");
                }

                return [['rawExpression' => 'status', 'isI18n' => isset($attributes['locale']), 'order' => null]];
            }

            // 'field:asc' shorthand used throughout Strapi
            $direction = null;
            if (str_contains($orderBy, ':')) {
                [$orderBy, $direction] = explode(':', $orderBy, 2);
            }

            if (!isset($attributes[$orderBy])) {
                throw new \InvalidArgumentException("Attribute {$orderBy} not found on model {$uid}");
            }

            if (($attributes[$orderBy]['type'] ?? null) === 'relation' && $direction !== null) {
                throw new \InvalidArgumentException("You cannot order on relation types");
            }

            $columnName = Transform::toColumnName($meta, $orderBy);
            $value = ['column' => $qb->aliasColumn($columnName, $alias)];
            if ($direction !== null) {
                $value['order'] = $direction;
            }

            return [$value];
        }

        if (is_array($orderBy) && array_is_list($orderBy)) {
            $out = [];
            foreach ($orderBy as $value) {
                array_push($out, ...self::processOrderBy($value, $qb, $uid, $alias));
            }

            return $out;
        }

        if (is_array($orderBy)) {
            $out = [];
            foreach ($orderBy as $key => $direction) {
                $key = (string) $key;

                if ($key === 'status') {
                    if (!isset($attributes['publishedAt']) || !isset($attributes['documentId'])) {
                        throw new \InvalidArgumentException("Cannot order by status on model {$uid}: missing publishedAt or documentId");
                    }
                    $out[] = ['rawExpression' => 'status', 'isI18n' => isset($attributes['locale']), 'order' => $direction];
                    continue;
                }

                $attribute = $attributes[$key] ?? null;
                if ($attribute === null) {
                    throw new \InvalidArgumentException("Attribute {$key} not found on model {$uid}");
                }

                $type = (string) ($attribute['type'] ?? '');

                if (Types::isScalar($type)) {
                    $columnName = Transform::toColumnName($meta, $key);
                    $out[] = ['column' => $qb->aliasColumn($columnName, $alias), 'order' => $direction];
                    continue;
                }

                if ($type === 'relation' && isset($attribute['target'])) {
                    $subAlias = Join::createJoin($qb, $uid, $alias ?? $qb->alias, null, $key, $attribute);
                    array_push($out, ...self::processOrderBy($direction, $qb, $attribute['target'], $subAlias));
                    continue;
                }

                throw new \InvalidArgumentException("You cannot order on {$type} types");
            }

            return $out;
        }

        throw new \InvalidArgumentException('Invalid orderBy syntax');
    }

    public static function getStrapiOrderColumnAlias(string $column): string
    {
        return self::COL_STRAPI_ORDER_BY_PREFIX . '__' . str_replace('.', '_', $column);
    }

    /**
     * Wraps the original query with deep sorting (sort on a joined relation without duplicates):
     * baseQuery (filtered unsorted data) -> T (row-numbered partitions) -> resultQuery (distinct,
     * paginated, sorted data).
     */
    public static function wrapWithDeepSort(SqlBuilder $originalQuery, QueryBuilder $qb, Database $db, string $uid): SqlBuilder
    {
        $tableName = $db->metadata->get($uid)['tableName'];
        $orderBy = $qb->state['orderBy'];

        $columnOrderBy = array_values(array_filter($orderBy, static fn (array $ob): bool => isset($ob['column'])));
        $rawExpressionOrderBy = array_values(array_filter($orderBy, static fn (array $ob): bool => isset($ob['rawExpression'])));

        $resultQueryAlias = $qb->getAlias();
        $resultQuery = $db->sql()->from($tableName, $qb->mustUseAlias() ? $resultQueryAlias : null);

        $baseQuery = clone $originalQuery;
        $baseQueryAlias = $qb->getAlias();

        $baseQuery->clear('select')->clear('order')->clear('limit')->clear('offset');

        $baseSelect = ["{$qb->alias}.id"];
        foreach ($columnOrderBy as $orderByClause) {
            $baseSelect[] = $orderByClause['column'] . ' as ' . self::getStrapiOrderColumnAlias($orderByClause['column']);
        }
        $baseQuery->select($baseSelect);

        $partitionedQueryAlias = $qb->getAlias();

        $prefixedOrderBy = array_map(static fn (array $ob): array => [
            'column' => $baseQueryAlias . '.' . self::getStrapiOrderColumnAlias($ob['column']),
            'order' => $ob['order'] ?? null,
        ], $columnOrderBy);

        $partitionedQuery = $db->sql();
        $partitionSelect = ["{$baseQueryAlias}.id"];
        foreach ($prefixedOrderBy as $ob) {
            $partitionSelect[] = $ob['column'];
        }

        // Knex `orderBy(column, order, 'last')`: NULLS LAST (emulated with `IS NULL` on MySQL)
        $rowNumberOrder = [];
        foreach ($prefixedOrderBy as $ob) {
            $dir = strtoupper($ob['order'] ?? 'asc') === 'DESC' ? 'DESC' : 'ASC';
            $ref = $partitionedQuery->quoteRef($ob['column']);
            $rowNumberOrder[] = $db->dialect->client === 'mysql'
                ? "{$ref} IS NULL, {$ref} {$dir}"
                : "{$ref} {$dir} NULLS LAST";
        }
        $rowNumberSql = 'ROW_NUMBER() OVER (PARTITION BY ' . $partitionedQuery->quoteRef("{$baseQueryAlias}.id")
            . ($rowNumberOrder !== [] ? ' ORDER BY ' . implode(', ', $rowNumberOrder) : '') . ') AS '
            . $partitionedQuery->quoteIdentifier(self::COL_STRAPI_ROW_NUMBER);
        $partitionSelect[] = new Raw($rowNumberSql);

        $partitionedQuery->select($partitionSelect)->from($baseQuery, $baseQueryAlias);

        $stringSelect = array_values(array_filter($qb->state['select'], 'is_string'));
        $orderColumns = array_map(static fn (array $ob): string => $ob['column'], $columnOrderBy);
        $originalSelect = array_map(
            static fn (string $col): string => "{$resultQueryAlias}.{$col}",
            array_values(array_filter($stringSelect, static fn (string $s): bool => !in_array($s, $orderColumns, true))),
        );

        $resultQuery->select($originalSelect)
            ->innerJoin($partitionedQuery, $partitionedQueryAlias, static function (SqlBuilder $join) use ($partitionedQueryAlias, $resultQueryAlias): void {
                // literal 1: PDO binds parameters as strings and SQLite's ROW_NUMBER() has no affinity
                $join->on("{$partitionedQueryAlias}.id", "{$resultQueryAlias}.id")
                    ->onRaw($join->quoteRef("{$partitionedQueryAlias}." . self::COL_STRAPI_ROW_NUMBER) . ' = 1');
            });

        if ($qb->state['limit'] !== null && $qb->state['limit'] >= 0) {
            $resultQuery->limit($qb->state['limit']);
        }
        if ($qb->state['offset'] !== null) {
            $resultQuery->offset($qb->state['offset']);
        }
        if ($qb->state['first']) {
            $resultQuery->limit(1);
        }

        foreach ($columnOrderBy as $ob) {
            $resultQuery->orderBy($partitionedQueryAlias . '.' . self::getStrapiOrderColumnAlias($ob['column']), $ob['order'] ?? 'asc');
        }
        foreach ($rawExpressionOrderBy as $entry) {
            $resultQuery->orderBy(self::buildStatusSortExpression($resultQuery, $tableName, $resultQueryAlias, $entry['isI18n'] ?? false), $entry['order'] ?? 'asc');
        }
        $resultQuery->orderBy("{$partitionedQueryAlias}.id", 'asc');

        return $resultQuery;
    }
}
