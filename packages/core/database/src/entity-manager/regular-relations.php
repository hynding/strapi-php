<?php

declare(strict_types=1);

namespace Strapi\Database\EntityManager;

use Strapi\Database\Database;
use Strapi\Database\Metadata\Relations;
use Strapi\Database\Query\QueryBuilder;
use Strapi\Database\Query\Raw;
use Strapi\Database\Query\SqlBuilder;

/** Port of packages/core/database/src/entity-manager/regular-relations.ts. */
final class RegularRelations
{
    /**
     * Ids of the rows belonging to the same document as `$id` (so relations are not stolen from
     * the sibling draft/published row), as a subquery; or `[id]` for non content types.
     *
     * @return list<int|string>|SqlBuilder
     */
    private static function getDocumentSiblingIdsQuery(Database $db, ?string $tableName, int|string $id): array|SqlBuilder
    {
        $isContentType = false;
        // component join tables have no referencedTable on the inverse column (upstream: `undefined`)
        foreach ($tableName === null ? [] : $db->metadata as $model) {
            if ($model['tableName'] === $tableName && isset($model['attributes']['documentId'])) {
                $isContentType = true;
                break;
            }
        }

        if (!$isContentType || $tableName === null) {
            return [$id];
        }

        $documentIdSubQuery = $db->sql()->from($tableName)->select('document_id')->where('id', $id);

        return $db->sql()->select('id')->from($tableName)->whereIn('document_id', $documentIdSubQuery);
    }

    /**
     * If some relations currently exist for this oneToX relation, on the one side, this function
     * removes them and updates the inverse order if needed.
     *
     * @param array<string, mixed> $attribute
     * @param list<int|string> $relIdsToadd
     */
    public static function deletePreviousOneToAnyRelations(Database $db, int|string $id, array $attribute, array $relIdsToadd, mixed $trx = null): void
    {
        if (!(Relations::isBidirectional($attribute) && Relations::isOneToAny($attribute))) {
            throw new \InvalidArgumentException('deletePreviousOneToAnyRelations can only be called for bidirectional oneToAny relations');
        }

        $joinTable = $attribute['joinTable'];
        $joinColumn = $joinTable['joinColumn'];
        $inverseJoinColumn = $joinTable['inverseJoinColumn'];

        if ($relIdsToadd === []) {
            return;
        }

        $sql = $db->sql()->from($joinTable['name'])->delete()
            // Exclude the ids of the current document
            ->whereNotIn($joinColumn['name'], self::getDocumentSiblingIdsQuery($db, $joinColumn['referencedTable'] ?? null, $id))
            // Include all the ids that are being connected
            ->whereIn($inverseJoinColumn['name'], $relIdsToadd)
            ->where($joinTable['on'] ?? []);
        $sql->run();

        self::cleanOrderColumns($db, $attribute, null, $relIdsToadd, $trx);
    }

    /**
     * If a relation currently exists for this xToOne relation, this function removes it and
     * updates the inverse order if needed.
     *
     * @param array<string, mixed> $attribute
     */
    public static function deletePreviousAnyToOneRelations(Database $db, int|string $id, array $attribute, int|string|null $relIdToadd, mixed $trx = null): void
    {
        $joinTable = $attribute['joinTable'];
        $joinColumn = $joinTable['joinColumn'];
        $inverseJoinColumn = $joinTable['inverseJoinColumn'];

        if (!Relations::isAnyToOne($attribute)) {
            throw new \InvalidArgumentException('deletePreviousAnyToOneRelations can only be called for anyToOne relations');
        }

        if ($relIdToadd === null) {
            return;
        }

        // handling manyToOne
        if (Relations::isManyToAny($attribute)) {
            $relsToDelete = $db->sql()->select($inverseJoinColumn['name'])->from($joinTable['name'])
                ->where($joinColumn['name'], $id)
                ->whereNotIn($inverseJoinColumn['name'], self::getDocumentSiblingIdsQuery($db, $inverseJoinColumn['referencedTable'] ?? null, $relIdToadd))
                ->where($joinTable['on'] ?? [])
                ->rows();

            $relIdsToDelete = array_map(static fn (array $r): int|string => self::toId($r[$inverseJoinColumn['name']]), $relsToDelete);

            if ($relIdsToDelete === []) {
                return;
            }

            (new QueryBuilder($joinTable['name'], $db))
                ->delete()
                ->where([$joinColumn['name'] => $id, $inverseJoinColumn['name'] => ['$in' => $relIdsToDelete]])
                ->where($joinTable['on'] ?? [])
                ->transacting($trx)
                ->execute();

            self::cleanOrderColumns($db, $attribute, null, $relIdsToDelete, $trx);
        } else {
            // handling oneToOne
            $db->sql()->from($joinTable['name'])->delete()
                ->where($joinColumn['name'], $id)
                ->whereNotIn($inverseJoinColumn['name'], self::getDocumentSiblingIdsQuery($db, $inverseJoinColumn['referencedTable'] ?? null, $relIdToadd))
                ->where($joinTable['on'] ?? [])
                ->run();
        }
    }

    /**
     * Delete all or some relations of an entity field.
     *
     * @param array<string, mixed> $attribute
     * @param list<int|string> $relIdsToNotDelete
     * @param list<int|string>|'all' $relIdsToDelete
     */
    public static function deleteRelations(Database $db, int|string $id, array $attribute, array|string $relIdsToDelete = [], array $relIdsToNotDelete = [], mixed $trx = null): void
    {
        $joinTable = $attribute['joinTable'];
        $joinColumn = $joinTable['joinColumn'];
        $inverseJoinColumn = $joinTable['inverseJoinColumn'];
        $all = $relIdsToDelete === 'all';

        if (Relations::hasOrderColumn($attribute) || Relations::hasInverseOrderColumn($attribute)) {
            $lastId = 0;
            $done = false;
            $batchSize = 100;

            while (!$done) {
                $where = [
                    $joinColumn['name'] => $id,
                    'id' => ['$gt' => $lastId],
                    $inverseJoinColumn['name'] => $all ? ['$notIn' => $relIdsToNotDelete] : ['$notIn' => $relIdsToNotDelete, '$in' => $relIdsToDelete],
                ];

                $batchToDelete = (new QueryBuilder($joinTable['name'], $db))
                    ->select(['id', $inverseJoinColumn['name']])
                    ->where($where)
                    ->where($joinTable['on'] ?? [])
                    ->orderBy('id')
                    ->limit($batchSize)
                    ->transacting($trx)
                    ->execute();
                /** @var list<array<string, mixed>> $batchToDelete */

                $done = count($batchToDelete) < $batchSize;
                $last = $batchToDelete === [] ? null : $batchToDelete[array_key_last($batchToDelete)];
                $lastId = $last['id'] ?? 0;

                $batchIds = array_map(static fn (array $r): int|string => self::toId($r[$inverseJoinColumn['name']]), $batchToDelete);

                if ($batchIds === []) {
                    break;
                }

                (new QueryBuilder($joinTable['name'], $db))
                    ->delete()
                    ->where([$joinColumn['name'] => $id, $inverseJoinColumn['name'] => ['$in' => $batchIds]])
                    ->where($joinTable['on'] ?? [])
                    ->transacting($trx)
                    ->execute();

                self::cleanOrderColumns($db, $attribute, $id, $batchIds, $trx);
            }
        } else {
            $where = [
                $joinColumn['name'] => $id,
                $inverseJoinColumn['name'] => $all ? ['$notIn' => $relIdsToNotDelete] : ['$notIn' => $relIdsToNotDelete, '$in' => $relIdsToDelete],
            ];

            (new QueryBuilder($joinTable['name'], $db))
                ->delete()
                ->where($where)
                ->where($joinTable['on'] ?? [])
                ->transacting($trx)
                ->execute();
        }
    }

    /** Join-column values read back from the database are ids. */
    private static function toId(mixed $value): int|string
    {
        if (!is_int($value) && !is_string($value)) {
            throw new \UnexpectedValueException('Expected an id, got ' . get_debug_type($value));
        }

        return $value;
    }

    /**
     * Clean the order columns by ensuring the order values are continuous (1, 2, 3 and not 1, 5, 10).
     *
     * @param array<string, mixed> $attribute
     * @param list<int|string> $inverseRelIds
     */
    public static function cleanOrderColumns(Database $db, array $attribute, int|string|null $id = null, array $inverseRelIds = [], mixed $trx = null): void
    {
        if (!(Relations::hasOrderColumn($attribute) && $id !== null) && !(Relations::hasInverseOrderColumn($attribute) && $inverseRelIds !== [])) {
            return;
        }

        $joinTable = $attribute['joinTable'];
        $joinColumn = $joinTable['joinColumn'];
        $inverseJoinColumn = $joinTable['inverseJoinColumn'];
        $orderColumnName = $joinTable['orderColumnName'] ?? null;
        $inverseOrderColumnName = $joinTable['inverseOrderColumnName'] ?? null;

        $q = $db->connection->quoteSingleIdentifier(...);
        $joinTableName = $q($joinTable['name']);

        $updateOrderColumn = function () use ($db, $attribute, $id, $joinColumn, $orderColumnName, $joinTableName, $q): void {
            if (!Relations::hasOrderColumn($attribute) || $id === null || $orderColumnName === null) {
                return;
            }

            $select = sprintf(
                'SELECT %s, ROW_NUMBER() OVER (PARTITION BY %s ORDER BY %s) AS src_order FROM %s WHERE %s = ?',
                $q('id'),
                $q($joinColumn['name']),
                $q($orderColumnName),
                $joinTableName,
                $q($joinColumn['name']),
            );

            self::runOrderUpdate($db, $joinTableName, $q($orderColumnName), 'src_order', $select, [$id]);
        };

        $updateInverseOrderColumn = function () use ($db, $attribute, $inverseRelIds, $inverseJoinColumn, $inverseOrderColumnName, $joinTableName, $q): void {
            if (!Relations::hasInverseOrderColumn($attribute) || $inverseRelIds === [] || $inverseOrderColumnName === null) {
                return;
            }

            $select = sprintf(
                'SELECT %s, ROW_NUMBER() OVER (PARTITION BY %s ORDER BY %s) AS inv_order FROM %s WHERE %s IN (%s)',
                $q('id'),
                $q($inverseJoinColumn['name']),
                $q($inverseOrderColumnName),
                $joinTableName,
                $q($inverseJoinColumn['name']),
                implode(', ', array_fill(0, count($inverseRelIds), '?')),
            );

            self::runOrderUpdate($db, $joinTableName, $q($inverseOrderColumnName), 'inv_order', $select, $inverseRelIds);
        };

        // Run updates in a deterministic order to avoid lock cycles on the same join table.
        $updateOrderColumn();
        $updateInverseOrderColumn();
    }

    /** @param list<mixed> $bindings */
    private static function runOrderUpdate(Database $db, string $joinTableName, string $column, string $srcColumn, string $select, array $bindings): void
    {
        $q = $db->connection->quoteSingleIdentifier(...);

        switch ($db->dialect->client) {
            case 'mysql':
                $db->connection->executeStatement(
                    "UPDATE {$joinTableName} AS a, ({$select}) AS b SET a.{$column} = b.{$srcColumn} WHERE b.{$q('id')} = a.{$q('id')}",
                    $bindings,
                );
                break;
            case 'sqlite':
                // SQLite >= 3.33 supports UPDATE ... FROM; older versions get a correlated subquery
                // over a materialised window result (a plain correlated subquery would recompute
                // ROW_NUMBER() per row and always yield 1).
                if (version_compare((string) $db->connection->getServerVersion(), '3.33.0', '>=')) {
                    $db->connection->executeStatement(
                        "UPDATE {$joinTableName} AS a SET {$column} = b.{$srcColumn} FROM ({$select}) AS b WHERE b.{$q('id')} = a.{$q('id')}",
                        $bindings,
                    );
                    break;
                }
                $rows = $db->connection->fetchAllAssociative($select, $bindings);
                foreach ($rows as $row) {
                    $db->connection->executeStatement("UPDATE {$joinTableName} SET {$column} = ? WHERE {$q('id')} = ?", [$row[$srcColumn], $row['id']]);
                }
                break;
            default:
                $db->connection->executeStatement(
                    "UPDATE {$joinTableName} AS a SET {$column} = b.{$srcColumn} FROM ({$select}) AS b WHERE b.{$q('id')} = a.{$q('id')}",
                    $bindings,
                );
        }
    }
}
