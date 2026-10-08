<?php

declare(strict_types=1);

namespace Strapi\Database\Query\Helpers\Populate;

use Strapi\Database\Database;
use Strapi\Database\Query\Helpers\Transform;
use Strapi\Database\Query\QueryBuilder;
use Strapi\Database\Query\Raw;
use Strapi\Utils\SortQuery;

/**
 * Port of packages/core/database/src/query/helpers/populate/apply.ts: runs after the main fetch
 * and attaches the related rows to each result, one query per relation (per type for morphs).
 */
final class Apply
{
    /** Prefix for the join column selected along with the target rows, so it cannot clash with an attribute. */
    private const JOIN_COL_PREFIX = '__strapi';

    /**
     * @param list<array<string, mixed>> $results mutated in place
     * @param array<string, mixed> $populate
     */
    public static function applyPopulate(array &$results, array $populate, QueryBuilder $qb, string $uid): void
    {
        $db = $qb->db;
        $meta = $db->metadata->get($uid);

        if ($results === []) {
            return;
        }

        foreach ($populate as $attributeName => $populateParams) {
            $attributeName = (string) $attributeName;
            $attribute = $meta['attributes'][$attributeName] ?? null;

            if ($attribute === null || ($attribute['type'] ?? null) !== 'relation') {
                throw new \InvalidArgumentException("Invalid populate attribute {$attributeName}");
            }

            $populateValue = self::getPopulateValue($populateParams, $qb->state['filters']);
            $isCount = ($populateValue['count'] ?? null) === true;

            switch ($attribute['relation']) {
                case 'oneToOne':
                case 'manyToOne':
                    self::xToOne($db, $qb, $uid, $attribute, $attributeName, $results, $populateValue, $isCount);
                    break;
                case 'oneToMany':
                    self::oneToMany($db, $qb, $uid, $attribute, $attributeName, $results, $populateValue, $isCount);
                    break;
                case 'manyToMany':
                    self::manyToMany($db, $attribute, $attributeName, $results, $populateValue, $isCount);
                    break;
                case 'morphOne':
                case 'morphMany':
                    self::morphX($db, $uid, $attribute, $attributeName, $results, $populateValue);
                    break;
                case 'morphToMany':
                    self::morphToMany($db, $attribute, $attributeName, $results, $populateValue, $isCount);
                    break;
                case 'morphToOne':
                    self::morphToOne($db, $attribute, $attributeName, $results, $populateValue, $isCount);
                    break;
                default:
                    break;
            }
        }
    }

    /** @return array<string, mixed> */
    private static function pickPopulateParams(mixed $populate): array
    {
        if (!is_array($populate)) {
            return [];
        }

        $fieldsToPick = ['select', 'count', 'where', 'populate', 'orderBy', 'filters', 'ordering', 'on'];
        if (($populate['count'] ?? null) !== true) {
            $fieldsToPick[] = 'limit';
            $fieldsToPick[] = 'offset';
        }

        return array_intersect_key($populate, array_flip($fieldsToPick));
    }

    /** @return array<string, mixed> */
    private static function getPopulateValue(mixed $populate, mixed $filters): array
    {
        $populateValue = ['filters' => $filters, ...self::pickPopulateParams($populate)];

        if (isset($populateValue['on']) && is_array($populateValue['on'])) {
            foreach ($populateValue['on'] as $type => $value) {
                if (is_array($value) && !array_is_list($value)) {
                    $value['filters'] = $filters;
                    $populateValue['on'][$type] = $value;
                }
            }
        }

        return $populateValue;
    }

    /**
     * Join-table `order` preserves connect order when no explicit populate sort is set.
     *
     * @param array<string, mixed> $populateValue
     * @param array<string, mixed> $joinTable
     *
     * @return array<string, string>|null
     */
    private static function getJoinTableOrderBy(array $populateValue, array $joinTable): ?array
    {
        $explicitSort = $populateValue['orderBy'] ?? $populateValue['sort'] ?? null;

        if (SortQuery::hasSort($explicitSort) || empty($joinTable['orderBy'])) {
            return null;
        }

        $ordering = $populateValue['ordering'] ?? null;

        return array_map(static fn (string $v): string => is_string($ordering) ? $ordering : $v, $joinTable['orderBy']);
    }

    /**
     * @param list<array<string, mixed>> $results
     *
     * @return list<mixed>
     */
    private static function referencedValues(array $results, string $column): array
    {
        $values = [];
        foreach ($results as $r) {
            $v = $r[$column] ?? null;
            if ($v !== null) {
                $values[(string) $v] = $v;
            }
        }

        return array_values($values);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function groupBy(array $rows, string $key): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[(string) ($row[$key] ?? '')][] = $row;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $attribute
     * @param list<array<string, mixed>> $results
     * @param array<string, mixed> $populateValue
     */
    private static function xToOne(Database $db, QueryBuilder $qb, string $uid, array $attribute, string $attributeName, array &$results, array $populateValue, bool $isCount): void
    {
        $targetMeta = $db->metadata->get($attribute['target']);
        $fromTargetRow = static fn (?array $row): ?array => $row === null ? null : Transform::fromSingleRow($targetMeta, $row);

        if (!empty($attribute['joinColumn'])) {
            $joinColumnName = $attribute['joinColumn']['name'];
            $referencedColumnName = $attribute['joinColumn']['referencedColumn'];

            $referencedValues = self::referencedValues($results, $joinColumnName);

            if ($referencedValues === []) {
                foreach ($results as &$result) {
                    $result[$attributeName] = null;
                }
                unset($result);

                return;
            }

            $rows = $db->entityManager->createQueryBuilder($targetMeta['uid'])
                ->init($populateValue)
                ->addSelect("{$qb->alias}.{$referencedColumnName}")
                ->where([$referencedColumnName => $referencedValues])
                ->execute(['mapResults' => false]);

            $map = self::groupBy($rows, $referencedColumnName);

            foreach ($results as &$result) {
                $result[$attributeName] = $fromTargetRow($map[(string) ($result[$joinColumnName] ?? '')][0] ?? null);
            }
            unset($result);

            return;
        }

        if (!empty($attribute['joinTable'])) {
            $joinTable = $attribute['joinTable'];
            $populateQb = $db->entityManager->createQueryBuilder($targetMeta['uid']);

            $joinColumnName = $joinTable['joinColumn']['name'];
            $referencedColumnName = $joinTable['joinColumn']['referencedColumn'];

            $alias = $populateQb->getAlias();
            $joinColAlias = "{$alias}.{$joinColumnName}";
            $joinColRenameAs = self::JOIN_COL_PREFIX . $joinColumnName;
            $joinColSelect = "{$joinColAlias} as {$joinColRenameAs}";

            $referencedValues = self::referencedValues($results, $referencedColumnName);

            if ($isCount) {
                if ($referencedValues === []) {
                    foreach ($results as &$result) {
                        $result[$attributeName] = ['count' => 0];
                    }
                    unset($result);

                    return;
                }

                $rows = $populateQb
                    ->init($populateValue)
                    ->join([
                        'alias' => $alias,
                        'referencedTable' => $joinTable['name'],
                        'referencedColumn' => $joinTable['inverseJoinColumn']['name'],
                        'rootColumn' => $joinTable['inverseJoinColumn']['referencedColumn'],
                        'rootTable' => $populateQb->alias,
                        'on' => $joinTable['on'] ?? null,
                    ])
                    ->select([$joinColAlias, new Raw('count(*) AS ' . $populateQb->quoteIdentifier('count'))])
                    ->where([$joinColAlias => $referencedValues])
                    ->groupBy([$joinColAlias])
                    ->execute(['mapResults' => false]);

                $map = [];
                foreach ($rows as $row) {
                    $map[(string) $row[$joinColumnName]] = ['count' => (int) $row['count']];
                }

                foreach ($results as &$result) {
                    $result[$attributeName] = $map[(string) ($result[$referencedColumnName] ?? '')] ?? ['count' => 0];
                }
                unset($result);

                return;
            }

            if ($referencedValues === []) {
                foreach ($results as &$result) {
                    $result[$attributeName] = null;
                }
                unset($result);

                return;
            }

            $rows = $populateQb
                ->init($populateValue)
                ->join([
                    'alias' => $alias,
                    'referencedTable' => $joinTable['name'],
                    'referencedColumn' => $joinTable['inverseJoinColumn']['name'],
                    'rootColumn' => $joinTable['inverseJoinColumn']['referencedColumn'],
                    'rootTable' => $populateQb->alias,
                    'on' => $joinTable['on'] ?? null,
                    'orderBy' => self::getJoinTableOrderBy($populateValue, $joinTable),
                ])
                ->addSelect($joinColSelect)
                ->where([$joinColAlias => $referencedValues])
                ->execute(['mapResults' => false]);

            $map = self::groupBy($rows, $joinColRenameAs);

            foreach ($results as &$result) {
                $result[$attributeName] = $fromTargetRow($map[(string) ($result[$referencedColumnName] ?? '')][0] ?? null);
            }
            unset($result);
        }
    }

    /**
     * @param array<string, mixed> $attribute
     * @param list<array<string, mixed>> $results
     * @param array<string, mixed> $populateValue
     */
    private static function oneToMany(Database $db, QueryBuilder $qb, string $uid, array $attribute, string $attributeName, array &$results, array $populateValue, bool $isCount): void
    {
        $targetMeta = $db->metadata->get($attribute['target']);
        $fromTargetRows = static fn (array $rows): array => array_map(static fn (array $row): ?array => Transform::fromSingleRow($targetMeta, $row), $rows);

        if (!empty($attribute['joinColumn'])) {
            $joinColumnName = $attribute['joinColumn']['name'];
            $referencedColumnName = $attribute['joinColumn']['referencedColumn'];
            $on = $attribute['joinColumn']['on'] ?? null;

            $referencedValues = self::referencedValues($results, $joinColumnName);

            if ($referencedValues === []) {
                foreach ($results as &$result) {
                    $result[$attributeName] = null;
                }
                unset($result);

                return;
            }

            $rows = $db->entityManager->createQueryBuilder($targetMeta['uid'])
                ->init($populateValue)
                ->addSelect("{$qb->alias}.{$referencedColumnName}")
                ->where([
                    $referencedColumnName => $referencedValues,
                    ...(is_callable($on) ? $on(['populateValue' => $populateValue, 'results' => $results]) : []),
                ])
                ->execute(['mapResults' => false]);

            $map = self::groupBy($rows, $referencedColumnName);

            foreach ($results as &$result) {
                $result[$attributeName] = $fromTargetRows($map[(string) ($result[$joinColumnName] ?? '')] ?? []);
            }
            unset($result);

            return;
        }

        if (!empty($attribute['joinTable'])) {
            $joinTable = $attribute['joinTable'];
            $populateQb = $db->entityManager->createQueryBuilder($targetMeta['uid']);

            $joinColumnName = $joinTable['joinColumn']['name'];
            $referencedColumnName = $joinTable['joinColumn']['referencedColumn'];

            $alias = $populateQb->getAlias();
            $joinColAlias = "{$alias}.{$joinColumnName}";
            $joinColRenameAs = self::JOIN_COL_PREFIX . $joinColumnName;
            $joinColSelect = "{$joinColAlias} as {$joinColRenameAs}";

            $referencedValues = self::referencedValues($results, $referencedColumnName);

            if ($isCount) {
                if ($referencedValues === []) {
                    foreach ($results as &$result) {
                        $result[$attributeName] = ['count' => 0];
                    }
                    unset($result);

                    return;
                }

                $rows = $populateQb
                    ->init($populateValue)
                    ->join([
                        'alias' => $alias,
                        'referencedTable' => $joinTable['name'],
                        'referencedColumn' => $joinTable['inverseJoinColumn']['name'],
                        'rootColumn' => $joinTable['inverseJoinColumn']['referencedColumn'],
                        'rootTable' => $populateQb->alias,
                        'on' => $joinTable['on'] ?? null,
                    ])
                    ->select([$joinColSelect, new Raw('count(*) AS ' . $populateQb->quoteIdentifier('count'))])
                    ->where([$joinColAlias => $referencedValues])
                    ->groupBy([$joinColAlias])
                    ->execute(['mapResults' => false]);

                $map = [];
                foreach ($rows as $row) {
                    $map[(string) $row[$joinColRenameAs]] = ['count' => (int) $row['count']];
                }

                foreach ($results as &$result) {
                    $result[$attributeName] = $map[(string) ($result[$referencedColumnName] ?? '')] ?? ['count' => 0];
                }
                unset($result);

                return;
            }

            if ($referencedValues === []) {
                foreach ($results as &$result) {
                    $result[$attributeName] = [];
                }
                unset($result);

                return;
            }

            $rows = $populateQb
                ->init($populateValue)
                ->join([
                    'alias' => $alias,
                    'referencedTable' => $joinTable['name'],
                    'referencedColumn' => $joinTable['inverseJoinColumn']['name'],
                    'rootColumn' => $joinTable['inverseJoinColumn']['referencedColumn'],
                    'rootTable' => $populateQb->alias,
                    'on' => $joinTable['on'] ?? null,
                    'orderBy' => self::getJoinTableOrderBy($populateValue, $joinTable),
                ])
                ->addSelect($joinColSelect)
                ->where([$joinColAlias => $referencedValues])
                ->execute(['mapResults' => false]);

            $map = self::groupBy($rows, $joinColRenameAs);

            foreach ($results as &$result) {
                $result[$attributeName] = $fromTargetRows($map[(string) ($result[$referencedColumnName] ?? '')] ?? []);
            }
            unset($result);
        }
    }

    /**
     * @param array<string, mixed> $attribute
     * @param list<array<string, mixed>> $results
     * @param array<string, mixed> $populateValue
     */
    private static function manyToMany(Database $db, array $attribute, string $attributeName, array &$results, array $populateValue, bool $isCount): void
    {
        $targetMeta = $db->metadata->get($attribute['target']);
        $fromTargetRows = static fn (array $rows): array => array_map(static fn (array $row): ?array => Transform::fromSingleRow($targetMeta, $row), $rows);

        $joinTable = $attribute['joinTable'];
        $populateQb = $db->entityManager->createQueryBuilder($targetMeta['uid']);

        $joinColumnName = $joinTable['joinColumn']['name'];
        $referencedColumnName = $joinTable['joinColumn']['referencedColumn'];

        $alias = $populateQb->getAlias();
        $joinColAlias = "{$alias}.{$joinColumnName}";
        $joinColRenameAs = self::JOIN_COL_PREFIX . $joinColumnName;
        $joinColSelect = "{$joinColAlias} as {$joinColRenameAs}";

        $referencedValues = self::referencedValues($results, $referencedColumnName);

        if ($isCount) {
            if ($referencedValues === []) {
                foreach ($results as &$result) {
                    $result[$attributeName] = ['count' => 0];
                }
                unset($result);

                return;
            }

            $rows = $populateQb
                ->init($populateValue)
                ->join([
                    'alias' => $alias,
                    'referencedTable' => $joinTable['name'],
                    'referencedColumn' => $joinTable['inverseJoinColumn']['name'],
                    'rootColumn' => $joinTable['inverseJoinColumn']['referencedColumn'],
                    'rootTable' => $populateQb->alias,
                    'on' => $joinTable['on'] ?? null,
                ])
                ->select([$joinColAlias, new Raw('count(*) AS ' . $populateQb->quoteIdentifier('count'))])
                ->where([$joinColAlias => $referencedValues])
                ->groupBy([$joinColAlias])
                ->execute(['mapResults' => false]);

            $map = [];
            foreach ($rows as $row) {
                $map[(string) $row[$joinColumnName]] = ['count' => (int) $row['count']];
            }

            foreach ($results as &$result) {
                $result[$attributeName] = $map[(string) ($result[$referencedColumnName] ?? '')] ?? ['count' => 0];
            }
            unset($result);

            return;
        }

        if ($referencedValues === []) {
            foreach ($results as &$result) {
                $result[$attributeName] = [];
            }
            unset($result);

            return;
        }

        $rows = $populateQb
            ->init($populateValue)
            ->join([
                'alias' => $alias,
                'referencedTable' => $joinTable['name'],
                'referencedColumn' => $joinTable['inverseJoinColumn']['name'],
                'rootColumn' => $joinTable['inverseJoinColumn']['referencedColumn'],
                'rootTable' => $populateQb->alias,
                'on' => $joinTable['on'] ?? null,
                'orderBy' => self::getJoinTableOrderBy($populateValue, $joinTable),
            ])
            ->addSelect($joinColSelect)
            ->where([$joinColAlias => $referencedValues])
            ->execute(['mapResults' => false]);

        $map = self::groupBy($rows, $joinColRenameAs);

        foreach ($results as &$result) {
            $result[$attributeName] = $fromTargetRows($map[(string) ($result[$referencedColumnName] ?? '')] ?? []);
        }
        unset($result);
    }

    /**
     * morphOne / morphMany (e.g. media): the target holds the morph columns or morph join table.
     *
     * @param array<string, mixed> $attribute
     * @param list<array<string, mixed>> $results
     * @param array<string, mixed> $populateValue
     */
    private static function morphX(Database $db, string $uid, array $attribute, string $attributeName, array &$results, array $populateValue): void
    {
        $target = $attribute['target'];
        $morphBy = $attribute['morphBy'];
        $targetMeta = $db->metadata->get($target);
        $isOne = $attribute['relation'] === 'morphOne';

        $fromTargetRow = static fn (?array $row): ?array => $row === null ? null : Transform::fromSingleRow($targetMeta, $row);
        $fromTargetRows = static fn (array $rows): array => array_map(static fn (array $row): ?array => Transform::fromSingleRow($targetMeta, $row), $rows);

        $targetAttribute = $targetMeta['attributes'][$morphBy];

        if (($targetAttribute['relation'] ?? null) === 'morphToOne') {
            $idColumn = $targetAttribute['morphColumn']['idColumn'];
            $typeColumn = $targetAttribute['morphColumn']['typeColumn'];

            $referencedValues = self::referencedValues($results, $idColumn['referencedColumn']);

            if ($referencedValues === []) {
                foreach ($results as &$result) {
                    $result[$attributeName] = $isOne ? null : [];
                }
                unset($result);

                return;
            }

            $rows = $db->entityManager->createQueryBuilder($target)
                ->init($populateValue)
                ->where([$idColumn['name'] => $referencedValues, $typeColumn['name'] => $uid])
                ->execute(['mapResults' => false]);

            $map = self::groupBy($rows, $idColumn['name']);

            foreach ($results as &$result) {
                $matching = $map[(string) ($result[$idColumn['referencedColumn']] ?? '')] ?? [];
                $result[$attributeName] = $isOne ? $fromTargetRow($matching[0] ?? null) : $fromTargetRows($matching);
            }
            unset($result);
        } elseif (($targetAttribute['relation'] ?? null) === 'morphToMany') {
            $joinTable = $targetAttribute['joinTable'];
            $joinColumn = $joinTable['joinColumn'];
            $idColumn = $joinTable['morphColumn']['idColumn'];
            $typeColumn = $joinTable['morphColumn']['typeColumn'];

            $referencedValues = self::referencedValues($results, $idColumn['referencedColumn']);

            if ($referencedValues === []) {
                foreach ($results as &$result) {
                    $result[$attributeName] = $isOne ? null : [];
                }
                unset($result);

                return;
            }

            $populateQb = $db->entityManager->createQueryBuilder($target);
            $alias = $populateQb->getAlias();

            $rows = $populateQb
                ->init($populateValue)
                ->join([
                    'alias' => $alias,
                    'referencedTable' => $joinTable['name'],
                    'referencedColumn' => $joinColumn['name'],
                    'rootColumn' => $joinColumn['referencedColumn'],
                    'rootTable' => $populateQb->alias,
                    'on' => [...($joinTable['on'] ?? []), 'field' => $attributeName],
                    'orderBy' => self::getJoinTableOrderBy($populateValue, $joinTable),
                ])
                ->addSelect(["{$alias}.{$idColumn['name']}", "{$alias}.{$typeColumn['name']}"])
                ->where([
                    "{$alias}.{$idColumn['name']}" => $referencedValues,
                    "{$alias}.{$typeColumn['name']}" => $uid,
                ])
                ->execute(['mapResults' => false]);

            $map = self::groupBy($rows, $idColumn['name']);

            foreach ($results as &$result) {
                $matching = $map[(string) ($result[$idColumn['referencedColumn']] ?? '')] ?? [];
                $result[$attributeName] = $isOne ? $fromTargetRow($matching[0] ?? null) : $fromTargetRows($matching);
            }
            unset($result);
        }
    }

    /**
     * morphToMany (dynamic zones, upload `related`): rows of the join table, then one query per type.
     *
     * @param array<string, mixed> $attribute
     * @param list<array<string, mixed>> $results
     * @param array<string, mixed> $populateValue
     */
    private static function morphToMany(Database $db, array $attribute, string $attributeName, array &$results, array $populateValue, bool $isCount): void
    {
        $joinTable = $attribute['joinTable'];
        $joinColumn = $joinTable['joinColumn'];
        $morphColumn = $joinTable['morphColumn'];
        $idColumn = $morphColumn['idColumn'];
        $typeColumn = $morphColumn['typeColumn'];
        $typeField = $morphColumn['typeField'] ?? '__type';

        $referencedValues = self::referencedValues($results, $joinColumn['referencedColumn']);

        $joinRowsRaw = $referencedValues === [] ? [] : $db->entityManager->createQueryBuilder($joinTable['name'])
            ->where([$joinColumn['name'] => $referencedValues, ...($joinTable['on'] ?? [])])
            ->orderBy([$joinColumn['name'], 'order'])
            ->execute(['mapResults' => false]);

        $allowedTypes = isset($populateValue['on']) && is_array($populateValue['on']) ? array_keys($populateValue['on']) : null;

        $joinRows = $allowedTypes === null
            ? $joinRowsRaw
            : array_values(array_filter($joinRowsRaw, static fn (array $row): bool => in_array($row[$typeColumn['name']], $allowedTypes, true)));

        $joinMap = self::groupBy($joinRows, $joinColumn['name']);

        if ($isCount) {
            foreach ($results as &$result) {
                $result[$attributeName] = ['count' => count($joinMap[(string) ($result[$joinColumn['referencedColumn']] ?? '')] ?? [])];
            }
            unset($result);

            return;
        }

        $idsByType = [];
        foreach ($joinRows as $row) {
            $idValue = $row[$idColumn['name']] ?? null;
            $typeValue = $row[$typeColumn['name']] ?? null;
            if (!$idValue || !$typeValue) {
                continue;
            }
            $idsByType[(string) $typeValue][] = $idValue;
        }

        $on = $populateValue['on'] ?? null;
        $typePopulate = $populateValue;
        unset($typePopulate['on']);

        $map = [];
        foreach ($idsByType as $type => $ids) {
            // type was removed but still in morph relation
            if (!$db->metadata->has($type)) {
                $map[$type] = [];
                continue;
            }

            $typeQb = $db->entityManager->createQueryBuilder($type);
            $rows = $typeQb
                ->init(is_array($on) && isset($on[$type]) ? (is_array($on[$type]) ? $on[$type] : []) : $typePopulate) // `on: { [type]: true }`: init(true) reads no params upstream
                ->addSelect("{$typeQb->alias}.{$idColumn['referencedColumn']}")
                ->where([$idColumn['referencedColumn'] => array_values(array_unique($ids))])
                ->execute(['mapResults' => false]);

            $map[$type] = self::groupBy($rows, $idColumn['referencedColumn']);
        }

        foreach ($results as &$result) {
            $joinResults = $joinMap[(string) ($result[$joinColumn['referencedColumn']] ?? '')] ?? [];

            $matchingRows = [];
            foreach ($joinResults as $joinResult) {
                $id = $joinResult[$idColumn['name']];
                $type = (string) $joinResult[$typeColumn['name']];

                if (!$db->metadata->has($type)) {
                    continue;
                }
                $targetMeta = $db->metadata->get($type);

                foreach ($map[$type][(string) $id] ?? [] as $row) {
                    // Spread target first so a same-named user attribute cannot override the morph type UID
                    $matchingRows[] = [...(Transform::fromSingleRow($targetMeta, $row) ?? []), $typeField => $type];
                }
            }

            $result[$attributeName] = $matchingRows;
        }
        unset($result);
    }

    /**
     * @param array<string, mixed> $attribute
     * @param list<array<string, mixed>> $results
     * @param array<string, mixed> $populateValue
     */
    private static function morphToOne(Database $db, array $attribute, string $attributeName, array &$results, array $populateValue, bool $isCount): void
    {
        $morphColumn = $attribute['morphColumn'];
        $idColumn = $morphColumn['idColumn'];
        $typeColumn = $morphColumn['typeColumn'];
        $typeField = $morphColumn['typeField'] ?? '__type';

        $idsByType = [];
        foreach ($results as $result) {
            $idValue = $result[$idColumn['name']] ?? null;
            $typeValue = $result[$typeColumn['name']] ?? null;
            if (!$idValue || !$typeValue) {
                continue;
            }
            $idsByType[(string) $typeValue][] = $idValue;
        }

        $on = $populateValue['on'] ?? null;
        $typePopulate = $populateValue;
        unset($typePopulate['on']);

        $allowedTypes = is_array($on)
            ? array_values(array_filter(array_keys($idsByType), static fn (string $type): bool => array_key_exists($type, $on)))
            : array_keys($idsByType);

        if ($isCount) {
            foreach ($results as &$result) {
                $id = $result[$idColumn['name']] ?? null;
                $type = $result[$typeColumn['name']] ?? null;
                $result[$attributeName] = ['count' => $id && $type && in_array((string) $type, $allowedTypes, true) ? 1 : 0];
            }
            unset($result);

            return;
        }

        $map = [];
        foreach ($idsByType as $type => $ids) {
            if (!in_array($type, $allowedTypes, true) || !$db->metadata->has($type)) {
                $map[$type] = [];
                continue;
            }

            $typeQb = $db->entityManager->createQueryBuilder($type);
            $rows = $typeQb
                ->init(is_array($on) && isset($on[$type]) ? (is_array($on[$type]) ? $on[$type] : []) : $typePopulate) // `on: { [type]: true }`: init(true) reads no params upstream
                ->addSelect("{$typeQb->alias}.{$idColumn['referencedColumn']}")
                ->where([$idColumn['referencedColumn'] => array_values(array_unique($ids))])
                ->execute(['mapResults' => false]);

            $map[$type] = self::groupBy($rows, $idColumn['referencedColumn']);
        }

        foreach ($results as &$result) {
            $id = $result[$idColumn['name']] ?? null;
            $type = $result[$typeColumn['name']] ?? null;

            if (!$type || !$id) {
                $result[$attributeName] = null;
                continue;
            }

            $row = $map[(string) $type][(string) $id][0] ?? null;
            if ($row === null || !$db->metadata->has((string) $type)) {
                $result[$attributeName] = null;
                continue;
            }

            $result[$attributeName] = [...(Transform::fromSingleRow($db->metadata->get((string) $type), $row) ?? []), $typeField => $type];
        }
        unset($result);
    }
}
