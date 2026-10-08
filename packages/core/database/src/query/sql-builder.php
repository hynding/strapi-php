<?php

declare(strict_types=1);

namespace Strapi\Database\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Strapi\Database\Dialects\Dialect;

/**
 * The subset of Knex's query builder that @strapi/database uses, compiled to SQL with
 * positional bindings. Doctrine DBAL's own QueryBuilder works on string expressions and has no
 * nested where callbacks, so the Strapi query helpers (where/join/order-by/populate) target this
 * class instead; it is to DBAL what Knex is to the drivers.
 *
 * Identifiers are quoted per part (`t0.title` → `"t0"."title"`); `*` and Raw fragments are left as is.
 */
final class SqlBuilder
{
    /** @var list<string|Raw> */
    private array $selects = [];

    private bool $distinct = false;

    private ?string $table = null;

    private ?string $alias = null;

    /** @var SqlBuilder|null a derived table used as FROM */
    private ?SqlBuilder $fromSub = null;

    /** @var list<array{type: string, table: string|SqlBuilder, alias: string, on: SqlBuilder}> */
    private array $joins = [];

    /** @var list<array{bool: string, sql: string, bindings: list<mixed>}> */
    private array $wheres = [];

    /** @var list<array{column: string|Raw, order: string}> */
    private array $orders = [];

    /** @var list<string|Raw> */
    private array $groups = [];

    private ?int $limit = null;

    private ?int $offset = null;

    private string $method = 'select';

    /** @var list<array<string, mixed>> */
    private array $insertRows = [];

    /** @var array<string, mixed> */
    private array $updateData = [];

    /** @var list<array{column: string, amount: int|float}> */
    private array $increments = [];

    /** @var list<array{column: string, amount: int|float}> */
    private array $decrements = [];

    /** @var list<string> */
    private array $returning = [];

    /** @var list<string>|null */
    private ?array $onConflict = null;

    /** @var list<string>|null */
    private ?array $merge = null;

    private bool $ignore = false;

    private bool $forUpdate = false;

    /** @var array{fn: string, column: string|Raw, alias: string, distinct: bool}|null */
    private ?array $aggregate = null;

    public function __construct(private readonly Dialect $dialect, private readonly Connection $connection)
    {
    }

    public function platform(): AbstractPlatform
    {
        return $this->connection->getDatabasePlatform();
    }

    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function sub(): self
    {
        return new self($this->dialect, $this->connection);
    }

    /** @param list<mixed> $bindings */
    public function raw(string $sql, array $bindings = []): Raw
    {
        return new Raw($sql, $bindings);
    }

    // --- FROM / SELECT -----------------------------------------------------------------

    public function from(string|SqlBuilder $table, ?string $alias = null): self
    {
        if ($table instanceof SqlBuilder) {
            $this->fromSub = $table;
            $this->table = null;
        } else {
            $this->table = $table;
        }
        $this->alias = $alias;

        return $this;
    }

    /** @param list<string|Raw>|string|Raw $columns */
    public function select(array|string|Raw $columns): self
    {
        $this->method = $this->method === 'select' ? 'select' : $this->method;
        foreach (is_array($columns) ? $columns : [$columns] as $column) {
            $this->selects[] = $column;
        }

        return $this;
    }

    public function distinct(bool $distinct = true): self
    {
        $this->distinct = $distinct;

        return $this;
    }

    public function clear(string $part): self
    {
        match ($part) {
            'select' => $this->selects = [],
            'order' => $this->orders = [],
            'limit' => $this->limit = null,
            'offset' => $this->offset = null,
            'where' => $this->wheres = [],
            default => throw new \InvalidArgumentException("Unknown clause {$part}"),
        };

        return $this;
    }

    public function count(string|Raw $column = '*', string $alias = 'count', bool $distinct = false): self
    {
        $this->aggregate = ['fn' => 'COUNT', 'column' => $column, 'alias' => $alias, 'distinct' => $distinct];

        return $this;
    }

    public function max(string|Raw $column, string $alias = 'max'): self
    {
        $this->aggregate = ['fn' => 'MAX', 'column' => $column, 'alias' => $alias, 'distinct' => false];

        return $this;
    }

    public function min(string|Raw $column, string $alias = 'min'): self
    {
        $this->aggregate = ['fn' => 'MIN', 'column' => $column, 'alias' => $alias, 'distinct' => false];

        return $this;
    }

    // --- JOINS -------------------------------------------------------------------------

    /** @param callable(SqlBuilder): void $on receives a builder on which `on()`/`onVal()` are called */
    public function leftJoin(string|SqlBuilder $table, string $alias, callable $on): self
    {
        return $this->join('LEFT JOIN', $table, $alias, $on);
    }

    /** @param callable(SqlBuilder): void $on */
    public function innerJoin(string|SqlBuilder $table, string $alias, callable $on): self
    {
        return $this->join('INNER JOIN', $table, $alias, $on);
    }

    /** @param callable(SqlBuilder): void $on */
    private function join(string $type, string|SqlBuilder $table, string $alias, callable $on): self
    {
        $builder = $this->sub();
        $on($builder);
        $this->joins[] = ['type' => $type, 'table' => $table, 'alias' => $alias, 'on' => $builder];

        return $this;
    }

    /** Join condition `left = right` (both column references). */
    public function on(string $left, string $right): self
    {
        $this->wheres[] = ['bool' => 'AND', 'sql' => $this->quoteRef($left) . ' = ' . $this->quoteRef($right), 'bindings' => []];

        return $this;
    }

    public function andOn(string $left, string $right): self
    {
        return $this->on($left, $right);
    }

    /** Join condition `column = ?`. */
    public function onVal(string $column, mixed $value, string $operator = '='): self
    {
        if ($value === null) {
            $this->wheres[] = ['bool' => 'AND', 'sql' => $this->quoteRef($column) . ' IS NULL', 'bindings' => []];
        } else {
            $this->wheres[] = ['bool' => 'AND', 'sql' => $this->quoteRef($column) . " {$operator} ?", 'bindings' => [$value]];
        }

        return $this;
    }

    public function andOnVal(string $column, string $operator, mixed $value): self
    {
        return $this->onVal($column, $value, $operator);
    }

    /**
     * Raw join condition (already quoted).
     *
     * @param list<mixed> $bindings
     */
    public function onRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = ['bool' => 'AND', 'sql' => $sql, 'bindings' => $bindings];

        return $this;
    }

    // --- WHERE -------------------------------------------------------------------------

    /**
     * `where(callable)` opens a nested group, `where(column, value)` is equality,
     * `where(column, operator, value)` a comparison, `where(array)` a map of column => value.
     *
     * @param (callable(SqlBuilder): void)|string|array<string, mixed> $column
     */
    public function where(callable|string|array $column, mixed ...$args): self
    {
        return $this->addWhere('AND', $column, ...$args);
    }

    /** @param (callable(SqlBuilder): void)|string|array<string, mixed> $column */
    public function orWhere(callable|string|array $column, mixed ...$args): self
    {
        return $this->addWhere('OR', $column, ...$args);
    }

    public function whereNot(callable $callback): self
    {
        $sub = $this->sub();
        $callback($sub);
        $sql = $sub->compileWheres();
        if ($sql['sql'] !== '') {
            $this->wheres[] = ['bool' => 'AND', 'sql' => 'NOT (' . $sql['sql'] . ')', 'bindings' => $sql['bindings']];
        }

        return $this;
    }

    /** @param (callable(SqlBuilder): void)|string|array<string, mixed> $column */
    private function addWhere(string $bool, callable|string|array $column, mixed ...$args): self
    {
        if (is_callable($column)) {
            $sub = $this->sub();
            $column($sub);
            $compiled = $sub->compileWheres();
            if ($compiled['sql'] !== '') {
                $this->wheres[] = ['bool' => $bool, 'sql' => '(' . $compiled['sql'] . ')', 'bindings' => $compiled['bindings']];
            }

            return $this;
        }

        if (is_array($column)) {
            if ($column === []) {
                return $this;
            }
            $sub = $this->sub();
            foreach ($column as $col => $value) {
                if (is_array($value)) {
                    $sub->whereIn((string) $col, array_values($value));
                } elseif ($value === null) {
                    $sub->whereNull((string) $col);
                } else {
                    $sub->where((string) $col, $value);
                }
            }
            $compiled = $sub->compileWheres();
            $this->wheres[] = ['bool' => $bool, 'sql' => '(' . $compiled['sql'] . ')', 'bindings' => $compiled['bindings']];

            return $this;
        }

        if (count($args) === 1) {
            $operator = '=';
            $value = $args[0];
        } else {
            [$operator, $value] = $args;
        }

        if ($value === null && $operator === '=') {
            return $this->addNull($bool, $column, false);
        }

        if ($value instanceof SqlBuilder) {
            $compiled = $value->toSql();
            $this->wheres[] = ['bool' => $bool, 'sql' => $this->quoteRef($column) . " {$operator} (" . $compiled['sql'] . ')', 'bindings' => $compiled['bindings']];

            return $this;
        }

        if ($value instanceof Raw) {
            $this->wheres[] = ['bool' => $bool, 'sql' => $this->quoteRef($column) . " {$operator} " . $value->sql, 'bindings' => $value->bindings];

            return $this;
        }

        $this->wheres[] = ['bool' => $bool, 'sql' => $this->quoteRef($column) . " {$operator} ?", 'bindings' => [$value]];

        return $this;
    }

    private function addNull(string $bool, string $column, bool $not): self
    {
        $this->wheres[] = ['bool' => $bool, 'sql' => $this->quoteRef($column) . ($not ? ' IS NOT NULL' : ' IS NULL'), 'bindings' => []];

        return $this;
    }

    public function whereNull(string $column): self
    {
        return $this->addNull('AND', $column, false);
    }

    public function whereNotNull(string $column): self
    {
        return $this->addNull('AND', $column, true);
    }

    /** @param list<mixed>|SqlBuilder|Raw $values */
    public function whereIn(string $column, array|SqlBuilder|Raw $values, bool $not = false, string $bool = 'AND'): self
    {
        $keyword = $not ? 'NOT IN' : 'IN';

        if ($values instanceof SqlBuilder) {
            $compiled = $values->toSql();
            $this->wheres[] = ['bool' => $bool, 'sql' => $this->quoteRef($column) . " {$keyword} (" . $compiled['sql'] . ')', 'bindings' => $compiled['bindings']];

            return $this;
        }

        if ($values instanceof Raw) {
            $this->wheres[] = ['bool' => $bool, 'sql' => $this->quoteRef($column) . " {$keyword} (" . $values->sql . ')', 'bindings' => $values->bindings];

            return $this;
        }

        $values = array_values($values);
        if ($values === []) {
            // Knex: `whereIn` with an empty list is always false, `whereNotIn` always true
            $this->wheres[] = ['bool' => $bool, 'sql' => $not ? '1 = 1' : '1 = 0', 'bindings' => []];

            return $this;
        }

        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->wheres[] = ['bool' => $bool, 'sql' => $this->quoteRef($column) . " {$keyword} ({$placeholders})", 'bindings' => $values];

        return $this;
    }

    /** @param list<mixed>|SqlBuilder|Raw $values */
    public function whereNotIn(string $column, array|SqlBuilder|Raw $values): self
    {
        return $this->whereIn($column, $values, true);
    }

    /** @param array{0: mixed, 1: mixed} $range */
    public function whereBetween(string $column, array $range): self
    {
        $this->wheres[] = ['bool' => 'AND', 'sql' => $this->quoteRef($column) . ' BETWEEN ? AND ?', 'bindings' => [$range[0], $range[1]]];

        return $this;
    }

    /**
     * `??` placeholders are identifiers, `?` placeholders are bindings (Knex semantics).
     *
     * @param list<mixed> $bindings
     */
    public function whereRaw(string $sql, array $bindings = [], string $bool = 'AND'): self
    {
        [$sql, $bindings] = $this->interpolateIdentifiers($sql, $bindings);
        $this->wheres[] = ['bool' => $bool, 'sql' => $sql, 'bindings' => $bindings];

        return $this;
    }

    /** @param list<mixed> $bindings */
    public function orWhereRaw(string $sql, array $bindings = []): self
    {
        return $this->whereRaw($sql, $bindings, 'OR');
    }

    /**
     * @param list<mixed> $bindings
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function interpolateIdentifiers(string $sql, array $bindings): array
    {
        $out = '';
        $remaining = [];
        $i = 0;
        $len = strlen($sql);
        $bindingIndex = 0;
        while ($i < $len) {
            if ($sql[$i] === '?' && $i + 1 < $len && $sql[$i + 1] === '?') {
                $out .= $this->quoteRef((string) $bindings[$bindingIndex++]);
                $i += 2;
                continue;
            }
            if ($sql[$i] === '?') {
                $remaining[] = $bindings[$bindingIndex++];
            }
            $out .= $sql[$i];
            $i++;
        }

        return [$out, $remaining];
    }

    // --- ORDER / GROUP / LIMIT ---------------------------------------------------------

    public function orderBy(string|Raw $column, ?string $direction = 'asc'): self
    {
        $this->orders[] = ['column' => $column, 'order' => strtoupper($direction ?? 'asc') === 'DESC' ? 'DESC' : 'ASC'];

        return $this;
    }

    /** @param list<array{column: string|Raw, order?: string|null}> $orders */
    public function orderByMany(array $orders): self
    {
        foreach ($orders as $order) {
            $this->orderBy($order['column'], $order['order'] ?? 'asc');
        }

        return $this;
    }

    /** @param list<string|Raw>|string|Raw $columns */
    public function groupBy(array|string|Raw $columns): self
    {
        foreach (is_array($columns) ? $columns : [$columns] as $column) {
            $this->groups[] = $column;
        }

        return $this;
    }

    public function limit(?int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    public function offset(?int $offset): self
    {
        $this->offset = $offset;

        return $this;
    }

    public function forUpdate(): self
    {
        $this->forUpdate = true;

        return $this;
    }

    // --- WRITES ------------------------------------------------------------------------

    /** @param array<string, mixed>|list<array<string, mixed>> $rows */
    public function insert(array $rows): self
    {
        $this->method = 'insert';
        $this->insertRows = $rows === [] || array_is_list($rows) ? $rows : [$rows];

        return $this;
    }

    /** @param list<string> $columns */
    public function returning(array $columns): self
    {
        $this->returning = $columns;

        return $this;
    }

    /** @param list<string> $columns */
    public function onConflict(array $columns): self
    {
        $this->onConflict = $columns;

        return $this;
    }

    /** @param list<string> $columns */
    public function merge(array $columns): self
    {
        $this->merge = $columns;

        return $this;
    }

    public function ignore(): self
    {
        $this->ignore = true;

        return $this;
    }

    /** @param array<string, mixed> $data */
    public function update(array $data): self
    {
        $this->method = 'update';
        $this->updateData = $data;

        return $this;
    }

    public function increment(string $column, int|float $amount = 1): self
    {
        $this->method = 'update';
        $this->increments[] = ['column' => $column, 'amount' => $amount];

        return $this;
    }

    public function decrement(string $column, int|float $amount = 1): self
    {
        $this->method = 'update';
        $this->decrements[] = ['column' => $column, 'amount' => $amount];

        return $this;
    }

    public function delete(): self
    {
        $this->method = 'delete';

        return $this;
    }

    public function truncate(): self
    {
        $this->method = 'truncate';

        return $this;
    }

    // --- COMPILATION -------------------------------------------------------------------

    public function quoteIdentifier(string $identifier): string
    {
        return $this->platform()->quoteSingleIdentifier($identifier);
    }

    /** Quotes `alias.column`, `column`, `column as alias`; `*` passes through. */
    public function quoteRef(string|Raw $ref): string
    {
        if ($ref instanceof Raw) {
            return $ref->sql;
        }

        if (preg_match('/^(.+?)\s+as\s+(.+)$/i', $ref, $m) === 1) {
            return $this->quoteRef($m[1]) . ' AS ' . $this->quoteIdentifier($m[2]);
        }

        $parts = explode('.', $ref);

        return implode('.', array_map(fn (string $p): string => $p === '*' ? '*' : $this->quoteIdentifier($p), $parts));
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    public function compileWheres(): array
    {
        $sql = '';
        $bindings = [];
        foreach ($this->wheres as $i => $where) {
            $sql .= ($i === 0 ? '' : ' ' . $where['bool'] . ' ') . $where['sql'];
            array_push($bindings, ...$where['bindings']);
        }

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    public function toSql(): array
    {
        return match ($this->method) {
            'select' => $this->compileSelect(),
            'insert' => $this->compileInsert(),
            'update' => $this->compileUpdate(),
            'delete' => $this->compileDelete(),
            'truncate' => ['sql' => $this->platform()->getTruncateTableSQL($this->quoteIdentifier((string) $this->table)), 'bindings' => []],
            default => throw new \LogicException("Unknown query method {$this->method}"),
        };
    }

    public function method(): string
    {
        return $this->method;
    }

    /** @return list<string> */
    public function returningColumns(): array
    {
        return $this->returning;
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    private function compileFrom(): array
    {
        if ($this->fromSub !== null) {
            $compiled = $this->fromSub->toSql();

            return ['sql' => '(' . $compiled['sql'] . ')' . ($this->alias !== null ? ' AS ' . $this->quoteIdentifier($this->alias) : ''), 'bindings' => $compiled['bindings']];
        }

        $sql = $this->quoteIdentifier((string) $this->table);
        if ($this->alias !== null) {
            $sql .= ' AS ' . $this->quoteIdentifier($this->alias);
        }

        return ['sql' => $sql, 'bindings' => []];
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    private function compileJoins(): array
    {
        $sql = '';
        $bindings = [];
        foreach ($this->joins as $join) {
            if ($join['table'] instanceof SqlBuilder) {
                $compiled = $join['table']->toSql();
                $target = '(' . $compiled['sql'] . ')';
                array_push($bindings, ...$compiled['bindings']);
            } else {
                $target = $this->quoteIdentifier($join['table']);
            }
            $on = $join['on']->compileWheres();
            $sql .= ' ' . $join['type'] . ' ' . $target . ' AS ' . $this->quoteIdentifier($join['alias']) . ' ON ' . ($on['sql'] !== '' ? $on['sql'] : '1 = 1');
            array_push($bindings, ...$on['bindings']);
        }

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    private function compileOrders(): array
    {
        if ($this->orders === []) {
            return ['sql' => '', 'bindings' => []];
        }

        $parts = [];
        $bindings = [];
        foreach ($this->orders as $order) {
            if ($order['column'] instanceof Raw) {
                $parts[] = $order['column']->sql . ' ' . $order['order'];
                array_push($bindings, ...$order['column']->bindings);
            } else {
                $parts[] = $this->quoteRef($order['column']) . ' ' . $order['order'];
            }
        }

        return ['sql' => ' ORDER BY ' . implode(', ', $parts), 'bindings' => $bindings];
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    private function compileSelect(): array
    {
        $bindings = [];

        if ($this->aggregate !== null) {
            $column = $this->aggregate['column'];
            if ($column instanceof Raw) {
                $columnSql = $column->sql;
                array_push($bindings, ...$column->bindings);
            } else {
                $columnSql = $this->quoteRef($column);
            }
            $columns = sprintf('%s(%s%s) AS %s', $this->aggregate['fn'], $this->aggregate['distinct'] ? 'DISTINCT ' : '', $columnSql, $this->quoteIdentifier($this->aggregate['alias']));
            foreach ($this->selects as $select) {
                if ($select instanceof Raw) {
                    $columns = $select->sql . ', ' . $columns;
                    array_push($bindings, ...$select->bindings);
                } else {
                    $columns = $this->quoteRef($select) . ', ' . $columns;
                }
            }
        } else {
            $parts = [];
            foreach ($this->selects === [] ? ['*'] : $this->selects as $select) {
                if ($select instanceof Raw) {
                    $parts[] = $select->sql;
                    array_push($bindings, ...$select->bindings);
                } else {
                    $parts[] = $this->quoteRef($select);
                }
            }
            $columns = implode(', ', $parts);
        }

        $from = $this->compileFrom();
        array_push($bindings, ...$from['bindings']);

        $joins = $this->compileJoins();
        array_push($bindings, ...$joins['bindings']);

        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . $columns . ' FROM ' . $from['sql'] . $joins['sql'];

        $wheres = $this->compileWheres();
        if ($wheres['sql'] !== '') {
            $sql .= ' WHERE ' . $wheres['sql'];
            array_push($bindings, ...$wheres['bindings']);
        }

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map(fn (string|Raw $g): string => $g instanceof Raw ? $g->sql : $this->quoteRef($g), $this->groups));
        }

        $orders = $this->compileOrders();
        $sql .= $orders['sql'];
        array_push($bindings, ...$orders['bindings']);

        if ($this->limit !== null || $this->offset !== null) {
            $sql = $this->platform()->modifyLimitQuery($sql, $this->limit, $this->offset ?? 0);
        }

        // DBAL 4 has no `getForUpdateSQL()`; SQLite is the only supported platform without row locks
        if ($this->forUpdate && !$this->platform() instanceof SQLitePlatform) {
            $sql .= ' FOR UPDATE';
        }

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    private function compileInsert(): array
    {
        $table = $this->quoteIdentifier((string) $this->table);

        if ($this->insertRows === []) {
            throw new \LogicException('Nothing to insert');
        }

        $columns = [];
        foreach ($this->insertRows as $row) {
            foreach (array_keys($row) as $col) {
                $columns[$col] = true;
            }
        }
        $columns = array_keys($columns);

        $bindings = [];
        $valueGroups = [];
        foreach ($this->insertRows as $row) {
            $placeholders = [];
            foreach ($columns as $col) {
                $value = $row[$col] ?? null;
                if ($value instanceof Raw) {
                    $placeholders[] = $value->sql;
                    array_push($bindings, ...$value->bindings);
                } else {
                    $placeholders[] = '?';
                    $bindings[] = $value;
                }
            }
            $valueGroups[] = '(' . implode(', ', $placeholders) . ')';
        }

        $quotedColumns = implode(', ', array_map($this->quoteIdentifier(...), $columns));
        $client = $this->dialect->client;

        $sql = 'INSERT ';
        if ($this->onConflict !== null && $this->ignore && $client === 'mysql') {
            $sql = 'INSERT IGNORE ';
        }
        $sql .= "INTO {$table} ({$quotedColumns}) VALUES " . implode(', ', $valueGroups);

        if ($this->onConflict !== null) {
            if ($client === 'mysql') {
                if ($this->merge !== null) {
                    $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn (string $c): string => $this->quoteIdentifier($c) . ' = VALUES(' . $this->quoteIdentifier($c) . ')', $this->merge));
                }
            } else {
                $sql .= ' ON CONFLICT (' . implode(', ', array_map($this->quoteIdentifier(...), $this->onConflict)) . ')';
                if ($this->merge !== null) {
                    $sql .= ' DO UPDATE SET ' . implode(', ', array_map(fn (string $c): string => $this->quoteIdentifier($c) . ' = excluded.' . $this->quoteIdentifier($c), $this->merge));
                } else {
                    $sql .= ' DO NOTHING';
                }
            }
        }

        if ($this->returning !== [] && $this->dialect->useReturning()) {
            $sql .= ' RETURNING ' . implode(', ', array_map($this->quoteIdentifier(...), $this->returning));
        }

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    private function compileUpdate(): array
    {
        $table = $this->quoteIdentifier((string) $this->table);
        $bindings = [];
        $sets = [];
        foreach ($this->updateData as $column => $value) {
            if ($value instanceof Raw) {
                $sets[] = $this->quoteIdentifier((string) $column) . ' = ' . $value->sql;
                array_push($bindings, ...$value->bindings);
            } else {
                $sets[] = $this->quoteIdentifier((string) $column) . ' = ?';
                $bindings[] = $value;
            }
        }
        foreach ($this->increments as $incr) {
            $sets[] = $this->quoteIdentifier($incr['column']) . ' = ' . $this->quoteIdentifier($incr['column']) . ' + ?';
            $bindings[] = $incr['amount'];
        }
        foreach ($this->decrements as $decr) {
            $sets[] = $this->quoteIdentifier($decr['column']) . ' = ' . $this->quoteIdentifier($decr['column']) . ' - ?';
            $bindings[] = $decr['amount'];
        }

        if ($sets === []) {
            throw new \LogicException('Empty .update() call detected');
        }

        $sql = "UPDATE {$table}" . ($this->alias !== null ? ' AS ' . $this->quoteIdentifier($this->alias) : '') . ' SET ' . implode(', ', $sets);

        $wheres = $this->compileWheres();
        if ($wheres['sql'] !== '') {
            $sql .= ' WHERE ' . $wheres['sql'];
            array_push($bindings, ...$wheres['bindings']);
        }

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    private function compileDelete(): array
    {
        $sql = 'DELETE FROM ' . $this->quoteIdentifier((string) $this->table);
        $bindings = [];

        $wheres = $this->compileWheres();
        if ($wheres['sql'] !== '') {
            $sql .= ' WHERE ' . $wheres['sql'];
            array_push($bindings, ...$wheres['bindings']);
        }

        return ['sql' => $sql, 'bindings' => $bindings];
    }

    // --- EXECUTION ---------------------------------------------------------------------

    /**
     * @param list<mixed> $bindings
     *
     * @return list<mixed>
     */
    private function bindValues(array $bindings): array
    {
        return array_map(fn (mixed $v): mixed => $this->dialect->toDatabaseValue($v), $bindings);
    }

    /**
     * Runs the query. Select → list of rows; insert → list of `['id' => ...]` (or ids when no
     * RETURNING is available); update/delete → affected rows.
     *
     * @return list<array<string, mixed>>|int
     */
    public function run(): array|int
    {
        ['sql' => $sql, 'bindings' => $bindings] = $this->toSql();
        $bindings = $this->bindValues($bindings);

        switch ($this->method) {
            case 'select':
                return $this->connection->fetchAllAssociative($sql, $bindings);
            case 'insert':
                if ($this->returning !== [] && $this->dialect->useReturning()) {
                    return $this->connection->fetchAllAssociative($sql, $bindings);
                }
                $count = (int) $this->connection->executeStatement($sql, $bindings);
                if ($this->returning === []) {
                    return $count;
                }
                $first = (int) $this->connection->lastInsertId();
                $rows = [];
                // MySQL: lastInsertId is the first id of a multi-row insert
                for ($i = 0; $i < count($this->insertRows); $i++) {
                    $rows[] = [$this->returning[0] => $first + $i];
                }

                return $rows;
            default:
                return (int) $this->connection->executeStatement($sql, $bindings);
        }
    }

    /**
     * Runs a select and returns its rows (`run()` narrowed for the common case).
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        if ($this->method !== 'select') {
            throw new \LogicException('rows() can only be used with a select query');
        }

        ['sql' => $sql, 'bindings' => $bindings] = $this->toSql();

        return $this->connection->fetchAllAssociative($sql, $this->bindValues($bindings));
    }

    public function __clone()
    {
        foreach ($this->joins as $i => $join) {
            $this->joins[$i]['on'] = clone $join['on'];
        }
        if ($this->fromSub !== null) {
            $this->fromSub = clone $this->fromSub;
        }
    }
}
