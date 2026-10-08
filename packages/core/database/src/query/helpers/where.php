<?php

declare(strict_types=1);

namespace Strapi\Database\Query\Helpers;

use Strapi\Database\Fields\Fields;
use Strapi\Database\Query\QueryBuilder;
use Strapi\Database\Query\Raw;
use Strapi\Database\Query\SqlBuilder;
use Strapi\Database\Utils\Knex;
use Strapi\Database\Utils\Types;
use Strapi\Utils\Operators;

/**
 * Port of packages/core/database/src/query/helpers/where.ts.
 *
 * `processWhere()` turns Strapi filters (`{ title: { $contains: 'x' }, author: { name: 'y' } }`)
 * into column-level conditions (adding joins for nested relations); `applyWhere()` compiles those
 * onto a SqlBuilder with the full operator set.
 */
final class Where
{
    private const LIKE_SPECIAL_CHARS = '%_\\';

    /** @param array<string, mixed> $where */
    private static function isRecord(mixed $where): bool
    {
        return is_array($where) && !array_is_list($where);
    }

    /** @param array<string, mixed>|null $attribute */
    private static function castValue(mixed $value, ?array $attribute): mixed
    {
        if ($attribute === null) {
            return $value;
        }

        if (Types::isScalar((string) ($attribute['type'] ?? '')) && !Knex::isKnexQuery($value)) {
            return $value === null ? null : Fields::createField($attribute)->toDB($value);
        }

        return $value;
    }

    /** @param array<string, mixed>|null $attribute */
    private static function processSingleAttributeWhere(?array $attribute, mixed $where, string $operator = '$eq'): mixed
    {
        if (!self::isRecord($where)) {
            if (Operators::isOperatorOfType('cast', $operator)) {
                return self::castValue($where, $attribute);
            }

            return $where;
        }

        $filters = [];
        foreach ($where as $key => $value) {
            if (!Operators::isOperatorOfType('where', (string) $key)) {
                throw new \InvalidArgumentException("Undefined attribute level operator {$key}");
            }

            $filters[$key] = self::processAttributeWhere($attribute, $value, (string) $key);
        }

        return $filters;
    }

    /** @param array<string, mixed>|null $attribute */
    private static function processAttributeWhere(?array $attribute, mixed $where, string $operator = '$eq'): mixed
    {
        if (is_array($where) && array_is_list($where)) {
            return array_map(static fn (mixed $sub): mixed => self::processSingleAttributeWhere($attribute, $sub, $operator), $where);
        }

        return self::processSingleAttributeWhere($attribute, $where, $operator);
    }

    private static function processNested(mixed $where, QueryBuilder $qb, string $uid, ?string $alias): mixed
    {
        if (!self::isRecord($where)) {
            return $where;
        }

        return self::processWhere($where, $qb, $uid, $alias);
    }

    /** @return array<string, mixed>|list<array<string, mixed>> */
    private static function processRelationWhere(mixed $where, QueryBuilder $qb, string $uid, ?string $alias): array
    {
        $idAlias = $qb->aliasColumn('id', $alias);
        if (!self::isRecord($where)) {
            return [$idAlias => $where];
        }

        $keys = array_map('strval', array_keys($where));
        $operatorKeys = array_values(array_filter($keys, static fn (string $key): bool => Operators::isOperator($key)));

        if ($operatorKeys !== [] && count($operatorKeys) !== count($keys)) {
            throw new \InvalidArgumentException('Operator and non-operator keys cannot be mixed in a relation where clause');
        }

        if (count($operatorKeys) > 1) {
            throw new \InvalidArgumentException('Only one operator key is allowed in a relation where clause, but found: ' . implode(',', $operatorKeys));
        }

        if (count($operatorKeys) === 1) {
            $operator = $operatorKeys[0];

            if (Operators::isOperatorOfType('group', $operator)) {
                return self::processWhere($where, $qb, $uid, $alias);
            }

            return [$idAlias => [$operator => self::processNested($where[$operator], $qb, $uid, $alias)]];
        }

        return self::processWhere($where, $qb, $uid, $alias);
    }

    /**
     * Process where parameter.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $where
     *
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public static function processWhere(array $where, QueryBuilder $qb, string $uid, ?string $alias = null): array
    {
        if (array_is_list($where) && $where !== []) {
            return array_map(static fn (array $sub): array => self::processWhere($sub, $qb, $uid, $alias), $where);
        }

        $db = $qb->db;
        $meta = $db->metadata->get($uid);

        $filters = [];

        foreach ($where as $key => $value) {
            $key = (string) $key;

            // if operator $and $or -> process recursively
            if (Operators::isOperatorOfType('group', $key)) {
                if (!is_array($value) || !array_is_list($value)) {
                    throw new \InvalidArgumentException("Operator {$key} must be an array");
                }

                $filters[$key] = array_map(static fn (mixed $sub): mixed => self::processNested($sub, $qb, $uid, $alias), $value);
                continue;
            }

            if ($key === '$not') {
                $filters[$key] = self::processNested($value, $qb, $uid, $alias);
                continue;
            }

            if (Operators::isOperatorOfType('where', $key)) {
                throw new \InvalidArgumentException("Only \$and, \$or and \$not can only be used as root level operators. Found {$key}.");
            }

            $attribute = $meta['attributes'][$key] ?? null;

            if ($attribute === null) {
                $filters[$qb->aliasColumn($key, $alias)] = self::processAttributeWhere(null, $value);
                continue;
            }

            $type = (string) ($attribute['type'] ?? '');

            if (Types::isRelation($type) && isset($attribute['target'])) {
                $subAlias = Join::createJoin($qb, $uid, $alias ?? $qb->alias, null, $key, $attribute);

                $nestedWhere = self::processRelationWhere($value, $qb, $attribute['target'], $subAlias);

                // TODO: use a better merge logic (push to $and when collisions)
                foreach ($nestedWhere as $k => $v) {
                    $filters[$k] = $v;
                }

                continue;
            }

            if (Types::isScalar($type)) {
                $columnName = Transform::toColumnName($meta, $key);
                $aliasedColumnName = $qb->aliasColumn($columnName, $alias);

                $filters[$aliasedColumnName] = self::processAttributeWhere($attribute, $value);

                continue;
            }

            throw new \InvalidArgumentException("You cannot filter on {$type} types");
        }

        return $filters;
    }

    private static function applyOperator(SqlBuilder $qb, string $column, string $operator, mixed $value): void
    {
        if (is_array($value) && array_is_list($value) && !Operators::isOperatorOfType('array', $operator)) {
            $qb->where(static function (SqlBuilder $subQB) use ($column, $operator, $value): void {
                foreach ($value as $subValue) {
                    $subQB->orWhere(static function (SqlBuilder $innerQB) use ($column, $operator, $subValue): void {
                        self::applyOperator($innerQB, $column, $operator, $subValue);
                    });
                }
            });

            return;
        }

        $lower = self::fieldLowerFn($qb);
        $escape = self::likeEscapeClause($qb);

        switch ($operator) {
            case '$not':
                $qb->whereNot(static fn (SqlBuilder $sub) => self::applyWhereToColumn($sub, $column, $value));
                break;
            case '$in':
                $qb->whereIn($column, Knex::isKnexQuery($value) ? $value : (is_array($value) ? array_values($value) : [$value]));
                break;
            case '$notIn':
                $qb->whereNotIn($column, Knex::isKnexQuery($value) ? $value : (is_array($value) ? array_values($value) : [$value]));
                break;
            case '$eq':
                if ($value === null) {
                    $qb->whereNull($column);
                    break;
                }
                $qb->where($column, $value);
                break;
            case '$eqi':
                if ($value === null) {
                    $qb->whereNull($column);
                    break;
                }
                $qb->whereRaw("{$lower} = LOWER(?)", [$column, self::stringify($value)]);
                break;
            case '$ne':
                if ($value === null) {
                    $qb->whereNotNull($column);
                    break;
                }
                $qb->where($column, '<>', $value);
                break;
            case '$nei':
                if ($value === null) {
                    $qb->whereNotNull($column);
                    break;
                }
                $qb->whereRaw("{$lower} <> LOWER(?)", [$column, self::stringify($value)]);
                break;
            case '$gt':
                $qb->where($column, '>', $value);
                break;
            case '$gte':
                $qb->where($column, '>=', $value);
                break;
            case '$lt':
                $qb->where($column, '<', $value);
                break;
            case '$lte':
                $qb->where($column, '<=', $value);
                break;
            case '$null':
                if (self::truthy($value)) {
                    $qb->whereNull($column);
                } else {
                    $qb->whereNotNull($column);
                }
                break;
            case '$notNull':
                if (self::truthy($value)) {
                    $qb->whereNotNull($column);
                } else {
                    $qb->whereNull($column);
                }
                break;
            case '$between':
                $qb->whereBetween($column, [$value[0] ?? null, $value[1] ?? null]);
                break;
            case '$startsWith':
                $qb->whereRaw("?? LIKE ?{$escape}", [$column, self::escapeLike($value) . '%']);
                break;
            case '$startsWithi':
                $qb->whereRaw("{$lower} LIKE LOWER(?){$escape}", [$column, self::escapeLike($value) . '%']);
                break;
            case '$endsWith':
                $qb->whereRaw("?? LIKE ?{$escape}", [$column, '%' . self::escapeLike($value)]);
                break;
            case '$endsWithi':
                $qb->whereRaw("{$lower} LIKE LOWER(?){$escape}", [$column, '%' . self::escapeLike($value)]);
                break;
            case '$contains':
                $qb->whereRaw("?? LIKE ?{$escape}", [$column, '%' . self::escapeLike($value) . '%']);
                break;
            case '$notContains':
                $qb->whereRaw("?? NOT LIKE ?{$escape}", [$column, '%' . self::escapeLike($value) . '%']);
                break;
            case '$containsi':
                $qb->whereRaw("{$lower} LIKE LOWER(?){$escape}", [$column, '%' . self::escapeLike($value) . '%']);
                break;
            case '$notContainsi':
                $qb->whereRaw("{$lower} NOT LIKE LOWER(?){$escape}", [$column, '%' . self::escapeLike($value) . '%']);
                break;
            case '$jsonSupersetOf':
                // Experimental, only for internal use (MySQL, PostgreSQL)
                $json = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);
                if ($qb->dialect()->client === 'postgres') {
                    $qb->whereRaw('?? @> ?::jsonb', [$column, $json]);
                } elseif ($qb->dialect()->client === 'mysql') {
                    $qb->whereRaw('json_contains(??, ?)', [$column, $json]);
                } else {
                    throw new \InvalidArgumentException('$jsonSupersetOf is not supported on this database');
                }
                break;
            default:
                throw new \InvalidArgumentException("Undefined attribute level operator {$operator}");
        }
    }

    private static function applyWhereToColumn(SqlBuilder $qb, string $column, mixed $columnWhere): void
    {
        if (!self::isRecord($columnWhere)) {
            if (is_array($columnWhere)) {
                $qb->whereIn($column, array_values($columnWhere));

                return;
            }

            if ($columnWhere === null) {
                $qb->whereNull($column);

                return;
            }

            $qb->where($column, $columnWhere);

            return;
        }

        foreach ($columnWhere as $operator => $value) {
            self::applyOperator($qb, $column, (string) $operator, $value);
        }
    }

    /**
     * @param array<string, mixed>|list<array<string, mixed>> $where
     */
    public static function applyWhere(SqlBuilder $qb, array $where): void
    {
        if (array_is_list($where) && $where !== []) {
            $qb->where(static function (SqlBuilder $subQB) use ($where): void {
                foreach ($where as $subWhere) {
                    self::applyWhere($subQB, $subWhere);
                }
            });

            return;
        }

        foreach ($where as $key => $value) {
            $key = (string) $key;

            if ($key === '$and') {
                $qb->where(static function (SqlBuilder $subQB) use ($value): void {
                    foreach ($value ?? [] as $v) {
                        self::applyWhere($subQB, $v);
                    }
                });
                continue;
            }

            if ($key === '$or') {
                $qb->where(static function (SqlBuilder $subQB) use ($value): void {
                    foreach ($value ?? [] as $v) {
                        $subQB->orWhere(static function (SqlBuilder $inner) use ($v): void {
                            self::applyWhere($inner, $v);
                        });
                    }
                });
                continue;
            }

            if ($key === '$not') {
                $qb->whereNot(static function (SqlBuilder $sub) use ($value): void {
                    self::applyWhere($sub, $value ?? []);
                });
                continue;
            }

            self::applyWhereToColumn($qb, $key, $value);
        }
    }

    /**
     * Prefix unaliased root column keys with `alias` (e.g. `published_at` → `t0.published_at`).
     * Used for update/delete subqueries that join other tables sharing column names.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $where
     *
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public static function qualifyRootColumns(array $where, string $alias): array
    {
        if (array_is_list($where) && $where !== []) {
            return array_map(static fn (array $sub): array => self::qualifyRootColumns($sub, $alias), $where);
        }

        $result = [];
        foreach ($where as $key => $value) {
            $key = (string) $key;

            if (Operators::isOperatorOfType('group', $key)) {
                $result[$key] = is_array($value)
                    ? array_map(static fn (mixed $sub): mixed => self::isRecord($sub) ? self::qualifyRootColumns($sub, $alias) : $sub, $value)
                    : $value;
                continue;
            }

            if ($key === '$not' && self::isRecord($value)) {
                $result[$key] = self::qualifyRootColumns($value, $alias);
                continue;
            }

            $qualifiedKey = str_contains($key, '.') || Operators::isOperator($key) ? $key : "{$alias}.{$key}";
            $result[$qualifiedKey] = $value;
        }

        return $result;
    }

    private static function fieldLowerFn(SqlBuilder $qb): string
    {
        // Postgres requires string to be passed
        return $qb->dialect()->client === 'postgres' ? 'LOWER(CAST(?? AS VARCHAR))' : 'LOWER(??)';
    }

    private static function likeEscapeClause(SqlBuilder $qb): string
    {
        // SQLite has no default LIKE escape character and requires an explicit clause.
        return $qb->dialect()->client === 'sqlite' ? " ESCAPE '\\'" : '';
    }

    private static function escapeLike(mixed $value): string
    {
        return Search::escapeQuery(self::stringify($value), self::LIKE_SPECIAL_CHARS);
    }

    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR);
    }

    private static function truthy(mixed $value): bool
    {
        return !in_array($value, [false, 0, '', '0', 'false', null], true);
    }
}
