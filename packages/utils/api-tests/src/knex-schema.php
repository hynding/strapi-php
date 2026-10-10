<?php

declare(strict_types=1);

namespace Strapi\ApiTests;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Strapi\Database\Database;

/**
 * `knex.schema` in upstream tests ({@see Knex}): `hasTable`, `hasColumn`, `dropTable(IfExists)` and
 * `createTable(name, (t) => { ... })`. The table callback runs in the test process against a
 * recorder (lib/bridge.js) and arrives as its calls: `[{ method, args, chain: [[name, args]...] }]`,
 * e.g. `t.integer('pa').notNullable()` or `t.foreign(['pa', 'pb']).references(['a', 'b']).inTable(parent)`.
 */
final class KnexSchema
{
    /** knex column builders → DBAL types */
    private const TYPES = [
        'increments' => 'integer', 'bigIncrements' => 'bigint', 'integer' => 'integer', 'bigInteger' => 'bigint',
        'string' => 'string', 'text' => 'text', 'boolean' => 'boolean', 'date' => 'date', 'datetime' => 'datetime',
        'timestamp' => 'datetime', 'time' => 'time', 'float' => 'float', 'double' => 'float', 'decimal' => 'decimal',
        'json' => 'json', 'jsonb' => 'json',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    public function hasTable(string $table): bool
    {
        return $this->db->getConnection()->createSchemaManager()->tableExists($table);
    }

    public function hasColumn(string $table, string $column): bool
    {
        if (!$this->hasTable($table)) {
            return false;
        }
        foreach ($this->db->getConnection()->createSchemaManager()->listTableColumns($table) as $existing) {
            if (strcasecmp($existing->getName(), $column) === 0) {
                return true;
            }
        }

        return false;
    }

    public function dropTableIfExists(string $table): void
    {
        if ($this->hasTable($table)) {
            $this->dropTable($table);
        }
    }

    public function dropTable(string $table): void
    {
        $this->db->getConnection()->createSchemaManager()->dropTable($table);
    }

    /** @param list<array{method?: string, args?: list<mixed>, chain?: list<array{0: string, 1?: list<mixed>}>}> $calls */
    public function createTable(string $name, array $calls = []): void
    {
        $columns = [];
        $primary = [];
        $foreignKeys = [];
        $indexes = [];

        foreach ($calls as $call) {
            $method = (string) ($call['method'] ?? '');
            $args = $call['args'] ?? [];
            $chain = [];
            foreach ($call['chain'] ?? [] as $link) {
                $chain[$link[0]] = $link[1] ?? [];
            }

            if ($method === 'primary') {
                $primary = self::names($args[0] ?? []);
                continue;
            }
            if ($method === 'foreign') {
                $foreignKeys[] = self::foreignKey($name, self::names($args[0] ?? []), $chain);
                continue;
            }
            if ($method === 'unique' || $method === 'index') {
                $indexColumns = self::names($args[0] ?? []);
                $indexes[] = new Index($name . '_' . implode('_', $indexColumns) . '_' . $method, $indexColumns, $method === 'unique');
                continue;
            }
            if (!isset(self::TYPES[$method])) {
                throw new \InvalidArgumentException("api-tests knex: unsupported column builder {$method}()");
            }

            [$column] = self::names($args[0] ?? '');
            $increments = str_ends_with(strtolower($method), 'increments');
            $options = ['notnull' => $increments || array_key_exists('notNullable', $chain) || array_key_exists('primary', $chain), 'autoincrement' => $increments];
            if ($method === 'string') {
                $options['length'] = (int) ($args[1] ?? 255);
            }
            if (array_key_exists('defaultTo', $chain)) {
                $options['default'] = $chain['defaultTo'][0] ?? null;
            }
            if (array_key_exists('unsigned', $chain)) {
                $options['unsigned'] = true;
            }
            $columns[] = new Column($column, Type::getType(self::TYPES[$method]), $options);

            if ($increments || array_key_exists('primary', $chain)) {
                $primary[] = $column;
            }
            if (array_key_exists('unique', $chain)) {
                $indexes[] = new Index("{$name}_{$column}_unique", [$column], true);
            }
            if (array_key_exists('references', $chain)) {
                $foreignKeys[] = self::foreignKey($name, [$column], $chain);
            }
        }

        $table = new Table($name, $columns, $indexes, [], $foreignKeys);
        if ($primary !== []) {
            $table = $table->edit()->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames(...$primary)->create())->create();
        }
        $this->db->getConnection()->createSchemaManager()->createTable($table);
    }

    /**
     * `references('id').inTable('t')`, `references('t.id')`, `onDelete('CASCADE')`
     *
     * @param non-empty-list<non-empty-string> $columns
     * @param array<string, list<mixed>> $chain
     */
    private static function foreignKey(string $table, array $columns, array $chain): ForeignKeyConstraint
    {
        $referenced = self::names($chain['references'][0] ?? []);
        $referencedTable = (string) ($chain['inTable'][0] ?? '');
        if ($referencedTable === '' && count($referenced) === 1 && str_contains($referenced[0], '.')) {
            [$referencedTable, $referenced[0]] = explode('.', $referenced[0], 2);
        }
        $options = [];
        if (isset($chain['onDelete'][0])) {
            $options['onDelete'] = (string) $chain['onDelete'][0];
        }
        if (isset($chain['onUpdate'][0])) {
            $options['onUpdate'] = (string) $chain['onUpdate'][0];
        }

        return new ForeignKeyConstraint($columns, $referencedTable, $referenced, $table . '_' . implode('_', $columns) . '_foreign', $options);
    }

    /** @return non-empty-list<non-empty-string> */
    private static function names(mixed $value): array
    {
        $names = [];
        foreach (is_array($value) ? $value : [$value] as $name) {
            if (!is_string($name) || $name === '') {
                throw new \InvalidArgumentException('api-tests knex: expected column names, got ' . json_encode($value));
            }
            $names[] = $name;
        }
        if ($names === []) {
            throw new \InvalidArgumentException('api-tests knex: expected at least one column name');
        }

        return $names;
    }
}
