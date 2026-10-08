<?php

declare(strict_types=1);

namespace Strapi\Database\Query\Helpers;

use Strapi\Database\Query\QueryBuilder;
use Strapi\Database\Query\SqlBuilder;

/**
 * Port of packages/core/database/src/query/helpers/join.ts.
 *
 * @phpstan-type JoinArray array{method?: 'leftJoin'|'innerJoin', alias: string, referencedTable: string, referencedColumn: string, rootColumn: string, rootTable?: string, on?: array<string, mixed>|null, orderBy?: array<string, string>|null}
 */
final class Join
{
    /**
     * @param array<string, mixed> $joinTable
     * @param array<string, mixed> $targetMeta
     */
    public static function createPivotJoin(QueryBuilder $qb, string $alias, ?string $refAlias, array $joinTable, array $targetMeta): string
    {
        $joinAlias = $qb->getAlias();
        $qb->join([
            'alias' => $joinAlias,
            'referencedTable' => $joinTable['name'],
            'referencedColumn' => $joinTable['joinColumn']['name'],
            'rootColumn' => $joinTable['joinColumn']['referencedColumn'],
            'rootTable' => $alias,
            'on' => $joinTable['on'] ?? null,
        ]);

        $subAlias = $refAlias ?? $qb->getAlias();
        $qb->join([
            'alias' => $subAlias,
            'referencedTable' => $targetMeta['tableName'],
            'referencedColumn' => $joinTable['inverseJoinColumn']['referencedColumn'],
            'rootColumn' => $joinTable['inverseJoinColumn']['name'],
            'rootTable' => $joinAlias,
        ]);

        return $subAlias;
    }

    /**
     * Joins the relation `$attributeName` onto `$alias` and returns the alias of the target table.
     *
     * @param array<string, mixed> $attribute
     */
    public static function createJoin(QueryBuilder $qb, string $uid, string $alias, ?string $refAlias, string $attributeName, array $attribute): string
    {
        $db = $qb->db;

        if (($attribute['type'] ?? null) !== 'relation') {
            throw new \InvalidArgumentException("Cannot join on non relational field {$attributeName}");
        }

        $targetMeta = $db->metadata->get($attribute['target']);

        if (in_array($attribute['relation'], ['morphOne', 'morphMany'], true)) {
            $targetAttribute = $targetMeta['attributes'][$attribute['morphBy']];
            $joinTable = $targetAttribute['joinTable'] ?? null;
            $morphColumn = $targetAttribute['morphColumn'] ?? null;

            if ($morphColumn !== null) {
                $subAlias = $refAlias ?? $qb->getAlias();
                $qb->join([
                    'alias' => $subAlias,
                    'referencedTable' => $targetMeta['tableName'],
                    'referencedColumn' => $morphColumn['idColumn']['name'],
                    'rootColumn' => $morphColumn['idColumn']['referencedColumn'],
                    'rootTable' => $alias,
                    'on' => [$morphColumn['typeColumn']['name'] => $uid, ...($morphColumn['on'] ?? [])],
                ]);

                return $subAlias;
            }

            if ($joinTable !== null) {
                $joinAlias = $qb->getAlias();
                $qb->join([
                    'alias' => $joinAlias,
                    'referencedTable' => $joinTable['name'],
                    'referencedColumn' => $joinTable['morphColumn']['idColumn']['name'],
                    'rootColumn' => $joinTable['morphColumn']['idColumn']['referencedColumn'],
                    'rootTable' => $alias,
                    'on' => [$joinTable['morphColumn']['typeColumn']['name'] => $uid, 'field' => $attributeName],
                ]);

                $subAlias = $refAlias ?? $qb->getAlias();
                $qb->join([
                    'alias' => $subAlias,
                    'referencedTable' => $targetMeta['tableName'],
                    'referencedColumn' => $joinTable['joinColumn']['referencedColumn'],
                    'rootColumn' => $joinTable['joinColumn']['name'],
                    'rootTable' => $joinAlias,
                ]);

                return $subAlias;
            }

            return $alias;
        }

        $joinColumn = $attribute['joinColumn'] ?? null;
        if ($joinColumn !== null) {
            $subAlias = $refAlias ?? $qb->getAlias();
            $qb->join([
                'alias' => $subAlias,
                'referencedTable' => $targetMeta['tableName'],
                'referencedColumn' => $joinColumn['referencedColumn'],
                'rootColumn' => $joinColumn['name'],
                'rootTable' => $alias,
            ]);

            return $subAlias;
        }

        $joinTable = $attribute['joinTable'] ?? null;
        if ($joinTable !== null) {
            return self::createPivotJoin($qb, $alias, $refAlias, $joinTable, $targetMeta);
        }

        return $alias;
    }

    /** @param JoinArray $join */
    public static function applyJoin(SqlBuilder $sql, array $join): void
    {
        $method = $join['method'] ?? 'leftJoin';
        $alias = $join['alias'];
        $rootTable = $join['rootTable'] ?? null;

        $sql->{$method}($join['referencedTable'], $alias, static function (SqlBuilder $inner) use ($join, $alias, $rootTable): void {
            $inner->on("{$rootTable}.{$join['rootColumn']}", "{$alias}.{$join['referencedColumn']}");

            foreach ($join['on'] ?? [] as $key => $value) {
                if (!is_string($value) && is_callable($value)) {
                    continue;
                }
                $inner->onVal("{$alias}.{$key}", $value);
            }
        });

        foreach ($join['orderBy'] ?? [] as $column => $direction) {
            $sql->orderBy("{$alias}.{$column}", $direction);
        }
    }

    /** @param list<JoinArray> $joins */
    public static function applyJoins(SqlBuilder $sql, array $joins): void
    {
        foreach ($joins as $join) {
            self::applyJoin($sql, $join);
        }
    }
}
