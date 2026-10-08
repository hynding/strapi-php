<?php

declare(strict_types=1);

namespace Strapi\Database\Query;

use Strapi\Database\Database;
use Strapi\Database\Errors\DatabaseError;
use Strapi\Database\Query\Helpers\Join;
use Strapi\Database\Query\Helpers\OrderBy;
use Strapi\Database\Query\Helpers\Populate\Apply;
use Strapi\Database\Query\Helpers\Populate\Process;
use Strapi\Database\Query\Helpers\Search;
use Strapi\Database\Query\Helpers\Transform;
use Strapi\Database\Query\Helpers\Where;
use Strapi\Database\TransactionContext;

/**
 * Port of packages/core/database/src/query/query-builder.ts: the chainable, metadata-aware
 * query builder (`db.queryBuilder(uid)`). State is processed once (`processState`) into
 * column-level where/orderBy/joins, then compiled to a SqlBuilder (`getSqlQuery`) and run.
 *
 * @phpstan-import-type JoinArray from Join
 * @phpstan-import-type Meta from \Strapi\Database\Metadata\Metadata
 */
class QueryBuilder
{
    /** @var array<string, mixed> */
    public array $state;

    public string $alias;

    /** @var Meta */
    private array $meta;

    private string $tableName;

    /** @param array<string, mixed> $initialState */
    final public function __construct(public readonly string $uid, public readonly Database $db, array $initialState = [])
    {
        $this->meta = $db->metadata->get($uid);
        $this->tableName = $this->meta['tableName'];

        $this->state = $initialState + [
            'type' => 'select',
            'select' => [],
            'count' => null,
            'max' => null,
            'min' => null,
            'first' => false,
            'data' => null,
            'where' => [],
            'joins' => [],
            'populate' => null,
            'limit' => null,
            'offset' => null,
            'transaction' => null,
            'forUpdate' => false,
            'onConflict' => null,
            'merge' => null,
            'ignore' => false,
            'orderBy' => [],
            'groupBy' => [],
            'increments' => [],
            'decrements' => [],
            'aliasCounter' => 0,
            'filters' => null,
            'search' => null,
            'processed' => false,
        ];

        $this->alias = $this->getAlias();
    }

    public function getAlias(): string
    {
        $alias = 't' . $this->state['aliasCounter'];
        $this->state['aliasCounter']++;

        return $alias;
    }

    public function clone(): static
    {
        return new static($this->uid, $this->db, $this->state);
    }

    /**
     * The `select` state entry, typed.
     *
     * @return list<string|Raw>
     */
    private function selectState(): array
    {
        /** @var list<string|Raw> $select */
        $select = $this->state['select'];

        return $select;
    }

    /** @param string|Raw|list<string|Raw> $args */
    public function select(string|Raw|array $args): static
    {
        $this->state['type'] = 'select';
        $this->state['select'] = self::unique(is_array($args) ? $args : [$args]);

        return $this;
    }

    /** @param string|Raw|list<string|Raw> $args */
    public function addSelect(string|Raw|array $args): static
    {
        $this->state['select'] = self::unique([...$this->selectState(), ...(is_array($args) ? $args : [$args])]);

        return $this;
    }

    /** @param array<string, mixed>|list<array<string, mixed>> $data */
    public function insert(array $data): static
    {
        $this->state['type'] = 'insert';
        $this->state['data'] = $data;

        return $this;
    }

    /** @param list<string> $columns */
    public function onConflict(array $columns): static
    {
        $this->state['onConflict'] = $columns;

        return $this;
    }

    /** @param list<string> $columns */
    public function merge(array $columns): static
    {
        $this->state['merge'] = $columns;

        return $this;
    }

    public function ignore(): static
    {
        $this->state['ignore'] = true;

        return $this;
    }

    public function delete(): static
    {
        $this->state['type'] = 'delete';

        return $this;
    }

    public function ref(string $name): Raw
    {
        return new Raw($this->db->sql()->quoteRef(Transform::toColumnName($this->meta, $name)));
    }

    /** @param array<string, mixed> $data */
    public function update(array $data): static
    {
        $this->state['type'] = 'update';
        $this->state['data'] = $data;

        return $this;
    }

    public function increment(string $column, int|float $amount = 1): static
    {
        $this->state['type'] = 'update';
        $this->state['increments'][] = ['column' => $column, 'amount' => $amount];

        return $this;
    }

    public function decrement(string $column, int|float $amount = 1): static
    {
        $this->state['type'] = 'update';
        $this->state['decrements'][] = ['column' => $column, 'amount' => $amount];

        return $this;
    }

    public function count(string $count = 'id'): static
    {
        $this->state['type'] = 'count';
        $this->state['count'] = $count;

        return $this;
    }

    public function max(string $column): static
    {
        $this->state['type'] = 'max';
        $this->state['max'] = $column;

        return $this;
    }

    public function min(string $column): static
    {
        $this->state['type'] = 'min';
        $this->state['min'] = $column;

        return $this;
    }

    /** @param array<string, mixed> $where */
    public function where(array $where = []): static
    {
        if (array_is_list($where) && $where !== []) {
            throw new \InvalidArgumentException('Where must be an object');
        }

        $this->state['where'][] = $where;

        return $this;
    }

    public function limit(?int $limit): static
    {
        $this->state['limit'] = $limit;

        return $this;
    }

    public function offset(?int $offset): static
    {
        $this->state['offset'] = $offset;

        return $this;
    }

    public function orderBy(mixed $orderBy): static
    {
        $this->state['orderBy'] = $orderBy;

        return $this;
    }

    /** @param string|Raw|list<string|Raw> $groupBy */
    public function groupBy(string|Raw|array $groupBy): static
    {
        $this->state['groupBy'] = is_array($groupBy) ? $groupBy : [$groupBy];

        return $this;
    }

    public function populate(mixed $populate): static
    {
        $this->state['populate'] = $populate;

        return $this;
    }

    public function search(?string $query): static
    {
        $this->state['search'] = $query;

        return $this;
    }

    public function transacting(mixed $transaction): static
    {
        $this->state['transaction'] = $transaction;

        return $this;
    }

    public function forUpdate(): static
    {
        $this->state['forUpdate'] = true;

        return $this;
    }

    /** @param array<string, mixed> $params */
    public function init(array $params = []): static
    {
        if (isset($params['where'])) {
            $this->where($params['where']);
        }

        if (isset($params['_q'])) {
            $this->search((string) $params['_q']);
        }

        if (isset($params['select'])) {
            $this->select($params['select']);
        } else {
            $this->select('*');
        }

        if (isset($params['limit'])) {
            $this->limit((int) $params['limit']);
        }

        if (isset($params['offset'])) {
            $this->offset((int) $params['offset']);
        }

        if (isset($params['orderBy'])) {
            $this->orderBy($params['orderBy']);
        }

        if (isset($params['groupBy'])) {
            $this->groupBy($params['groupBy']);
        }

        if (isset($params['populate'])) {
            $this->populate($params['populate']);
        }

        if (isset($params['filters'])) {
            $this->filters($params['filters']);
        }

        return $this;
    }

    public function filters(mixed $filters): void
    {
        $this->state['filters'] = $filters;
    }

    public function first(): static
    {
        $this->state['first'] = true;

        return $this;
    }

    /**
     * @param JoinArray|array{targetField: string, alias?: string} $join
     */
    public function join(array $join): static
    {
        if (empty($join['targetField'])) {
            $this->state['joins'][] = $join;

            return $this;
        }

        $attribute = $this->meta['attributes'][$join['targetField']] ?? null;
        if ($attribute === null) {
            throw new \InvalidArgumentException("Unknown attribute {$join['targetField']}");
        }

        Join::createJoin($this, $this->uid, $this->alias, $join['alias'] ?? null, $join['targetField'], $attribute);

        return $this;
    }

    public function mustUseAlias(): bool
    {
        return in_array($this->state['type'], ['select', 'count'], true);
    }

    public function aliasColumn(mixed $key, ?string $alias = null): mixed
    {
        if (!is_string($key)) {
            return $key;
        }

        if (str_contains($key, '.')) {
            return $key;
        }

        if ($alias !== null) {
            return "{$alias}.{$key}";
        }

        return $this->mustUseAlias() ? "{$this->alias}.{$key}" : $key;
    }

    /** @param list<mixed> $bindings */
    public function raw(string $sql, array $bindings = []): Raw
    {
        return new Raw($sql, $bindings);
    }

    public function quoteIdentifier(string $identifier): string
    {
        return $this->db->connection->quoteSingleIdentifier($identifier);
    }

    public function shouldUseSubQuery(): bool
    {
        return in_array($this->state['type'], ['delete', 'update'], true) && $this->state['joins'] !== [];
    }

    public function runSubQuery(): SqlBuilder
    {
        $originalType = $this->state['type'];

        // Build the inner SELECT from a clone that stays `select`; preserve the original alias
        // because joins were already baked against it.
        $sub = $this->clone();
        $sub->alias = $this->alias;
        $subQB = $sub->select('id')->getSqlQuery();

        $nestedSubQuery = $this->db->sql()->select('id')->from($subQB, 'subQuery');
        $qb = $this->db->sql()->from($this->tableName);

        if ($originalType === 'update') {
            return $qb->update($this->state['data'] ?? [])->whereIn('id', $nestedSubQuery);
        }

        return $qb->delete()->whereIn('id', $nestedSubQuery);
    }

    public function processState(): void
    {
        if ($this->state['processed']) {
            return;
        }

        $this->state['orderBy'] = OrderBy::processOrderBy($this->state['orderBy'], $this, $this->uid);

        if ($this->state['filters'] !== null) {
            if (is_callable($this->state['filters'])) {
                $filters = ($this->state['filters'])(['qb' => $this, 'uid' => $this->uid, 'meta' => $this->meta, 'db' => $this->db]);
                if ($filters !== null) {
                    $this->state['where'][] = $filters;
                }
            } else {
                $this->state['where'][] = $this->state['filters'];
            }
        }

        $this->state['where'] = Where::processWhere($this->state['where'], $this, $this->uid);

        // processWhere emits bare root columns while type is still update/delete (mustUseAlias is
        // false). Qualify them now that joins from the relation predicate are known.
        if ($this->shouldUseSubQuery()) {
            $this->state['where'] = Where::qualifyRootColumns($this->state['where'], $this->alias);
        }

        $this->state['populate'] = Process::processPopulate($this->state['populate'], $this, $this->uid);

        $this->state['data'] = Transform::toRow($this->meta, $this->state['data']);

        $this->ensurePaginationOrderStability();

        $this->processSelect();

        $this->state['processed'] = true;
    }

    /**
     * OFFSET/LIMIT without a unique ORDER BY is undefined behaviour; append id ASC for paginated
     * selects when it is not already the last sort key.
     */
    public function ensurePaginationOrderStability(): void
    {
        if ($this->state['type'] !== 'select' || $this->state['first']) {
            return;
        }

        if ($this->state['limit'] === null && $this->state['offset'] === null) {
            return;
        }

        if ($this->state['limit'] === -1) {
            return;
        }

        if ($this->shouldUseDeepSort()) {
            return;
        }

        if (!isset($this->meta['attributes']['id'])) {
            return;
        }

        $aliasedId = $this->aliasColumn(Transform::toColumnName($this->meta, 'id'));
        $orderBy = $this->state['orderBy'];
        $lastOrder = $orderBy === [] ? null : $orderBy[array_key_last($orderBy)];

        if ($lastOrder !== null && ($lastOrder['column'] ?? null) === $aliasedId) {
            return;
        }

        $this->state['orderBy'][] = ['column' => $aliasedId, 'order' => 'asc'];
    }

    public function shouldUseDistinct(): bool
    {
        return $this->state['joins'] !== [] && $this->state['groupBy'] === [];
    }

    public function shouldUseDeepSort(): bool
    {
        $joinAliases = array_map(static fn (array $join): string => $join['alias'], $this->state['joins']);

        foreach ($this->state['orderBy'] as $ob) {
            if (!isset($ob['column']) || !str_contains($ob['column'], '.')) {
                continue;
            }

            $col = explode('.', $ob['column']);
            for ($i = 0; $i < count($col) - 1; $i++) {
                $el = $col[$i];

                $isRelationAttribute = ($this->meta['attributes'][$el]['type'] ?? null) === 'relation';
                $isAliasedRelation = in_array($el, $joinAliases, true);

                if ($isRelationAttribute || $isAliasedRelation) {
                    return true;
                }
            }
        }

        return false;
    }

    public function processSelect(): void
    {
        $this->state['select'] = array_map(
            fn (string|Raw $field): string|Raw => $field instanceof Raw ? $field : Transform::toColumnName($this->meta, $field),
            $this->selectState(),
        );

        if ($this->shouldUseDistinct()) {
            $joinsOrderByColumns = [];
            foreach ($this->state['joins'] as $join) {
                foreach (array_keys($join['orderBy'] ?? []) as $key) {
                    $joinsOrderByColumns[] = $this->aliasColumn((string) $key, $join['alias']);
                }
            }
            $orderByColumns = [];
            foreach ($this->state['orderBy'] as $ob) {
                if (isset($ob['column'])) {
                    $orderByColumns[] = $ob['column'];
                }
            }

            $this->state['select'] = self::unique([...$joinsOrderByColumns, ...$orderByColumns, ...$this->selectState()]);

            // PostgreSQL requires every ORDER BY expression to appear in the SELECT list with DISTINCT
            foreach ($this->state['orderBy'] as $ob) {
                if (isset($ob['rawExpression'])) {
                    $this->state['select'][] = OrderBy::buildStatusSortExpression($this->db->sql(), $this->tableName, $this->alias, $ob['isI18n'] ?? false);
                }
            }
        }
    }

    public function getSqlQuery(): SqlBuilder
    {
        $this->processState();

        if ($this->shouldUseSubQuery()) {
            return $this->runSubQuery();
        }

        $qb = $this->db->sql()->from($this->tableName, $this->mustUseAlias() ? $this->alias : null);

        switch ($this->state['type']) {
            case 'select':
                $qb->select(array_map(fn (string|Raw $c): string|Raw => $c instanceof Raw ? $c : $this->aliasColumn($c), $this->selectState()));
                if ($this->shouldUseDistinct()) {
                    $qb->distinct();
                }
                break;
            case 'count':
                $dbColumnName = $this->aliasColumn(Transform::toColumnName($this->meta, $this->state['count']));
                $qb->count($dbColumnName, 'count', $this->shouldUseDistinct());
                break;
            case 'max':
                $qb->max($this->aliasColumn(Transform::toColumnName($this->meta, $this->state['max'])), 'max');
                break;
            case 'min':
                $qb->min($this->aliasColumn(Transform::toColumnName($this->meta, $this->state['min'])), 'min');
                break;
            case 'insert':
                $qb->insert($this->state['data'] ?? []);
                if (isset($this->meta['attributes']['id'])) {
                    $qb->returning(['id']);
                }
                break;
            case 'update':
                if ($this->state['data'] !== null && $this->state['data'] !== []) {
                    $qb->update($this->state['data']);
                }
                break;
            case 'delete':
                $qb->delete();
                break;
            case 'truncate':
                $qb->truncate();
                break;
            default:
                throw new \LogicException('Unknown query type');
        }

        if ($this->state['forUpdate']) {
            $qb->forUpdate();
        }

        foreach ($this->state['increments'] as $incr) {
            $qb->increment($incr['column'], $incr['amount']);
        }
        foreach ($this->state['decrements'] as $decr) {
            $qb->decrement($decr['column'], $decr['amount']);
        }

        if ($this->state['onConflict'] !== null) {
            if ($this->state['merge'] !== null) {
                $qb->onConflict($this->state['onConflict'])->merge($this->state['merge']);
            } elseif ($this->state['ignore']) {
                $qb->onConflict($this->state['onConflict'])->ignore();
            }
        }

        if ($this->state['limit'] !== null && $this->state['limit'] >= 0 && $this->state['limit'] !== 0) {
            $qb->limit($this->state['limit']);
        }

        if ($this->state['offset'] !== null && $this->state['offset'] > 0) {
            $qb->offset($this->state['offset']);
        }

        if ($this->state['first']) {
            $qb->limit(1);
        }

        if ($this->state['groupBy'] !== []) {
            $qb->groupBy($this->state['groupBy']);
        }

        if ($this->state['where'] !== []) {
            Where::applyWhere($qb, $this->state['where']);
        }

        if ($this->state['search'] !== null) {
            $search = $this->state['search'];
            $qb->where(function (SqlBuilder $subQb) use ($search): void {
                Search::applySearch($subQb, $search, $this, $this->uid);
            });
        }

        // Join orderBy (join-table ordinal for relations) must precede root orderBy
        if ($this->state['joins'] !== []) {
            Join::applyJoins($qb, $this->state['joins']);
        }

        if ($this->state['orderBy'] !== []) {
            foreach ($this->state['orderBy'] as $entry) {
                $descriptor = OrderBy::toSqlOrderByDescriptor($qb, $this->tableName, $this->alias, $entry);
                $qb->orderBy($descriptor['column'], $descriptor['order'] ?? 'asc');
            }
        }

        if ($this->shouldUseDeepSort()) {
            return OrderBy::wrapWithDeepSort($qb, $this, $this->db, $this->uid);
        }

        return $qb;
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    public function toSql(): array
    {
        return $this->getSqlQuery()->toSql();
    }

    /**
     * @param array{mapResults?: bool} $options
     *
     * @return mixed rows (select), `['id' => ...]` list (insert), affected count (update/delete),
     *               single row for `first()`, `{count}` for `count()`
     */
    public function execute(array $options = []): mixed
    {
        $mapResults = $options['mapResults'] ?? true;

        try {
            $qb = $this->getSqlQuery();

            $rows = $qb->run();

            if ($this->state['type'] === 'insert' && is_array($rows)) {
                // insert → list of ids like Knex `returning('id')`
                $rows = array_map(static fn (array $row): array => ['id' => $row['id'] ?? null], $rows);
            }

            if ($this->state['populate'] !== null && is_array($rows) && $rows !== []) {
                Apply::applyPopulate($rows, $this->state['populate'], $this, $this->uid);
            }

            $results = $rows;
            if ($mapResults && $this->state['type'] === 'select' && is_array($rows)) {
                $results = Transform::fromRow($this->meta, $rows);
            }

            if ($this->state['first'] && is_array($results)) {
                return $results[0] ?? null;
            }

            return $results;
        } catch (\Throwable $error) {
            if ($error instanceof DatabaseError) {
                throw $error;
            }
            $this->db->dialect->transformErrors($error);
        }
    }

    /**
     * @param list<string|Raw> $items
     *
     * @return list<string|Raw>
     */
    private static function unique(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            if ($item instanceof Raw) {
                $out[] = $item;
                continue;
            }
            if (isset($seen[$item])) {
                continue;
            }
            $seen[$item] = true;
            $out[] = $item;
        }

        return $out;
    }
}
