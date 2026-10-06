<?php

declare(strict_types=1);

namespace Strapi\Database\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Database\Database;
use Strapi\Database\Tests\Support\GetstartedDatabase;
use Strapi\Database\Utils\SchemaFactory;

/**
 * One database shared between Node Strapi and this port.
 *
 * fixtures/getstarted-knex.db was created with Knex (better-sqlite3) from upstream's schema of
 * the getstarted project, including the `strapi_database_schema` row with the hash Node computed,
 * the internal migrations log and one kitchensink row written the way Knex writes values.
 */
final class SharedDatabaseTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/strapi-php-shared-' . uniqid() . '.sqlite';
        copy(__DIR__ . '/fixtures/getstarted-knex.db', $this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    private function open(): Database
    {
        $db = new Database([
            'connection' => ['client' => 'sqlite', 'connection' => ['filename' => $this->file], 'useNullAsDefault' => true],
            'settings' => ['forceMigration' => true, 'runMigrations' => true, 'migrations' => ['dir' => '']],
        ]);
        $db->init(GetstartedDatabase::schemas());
        $db->metadata->add(SchemaFactory::coreStoreModel());
        $db->metadata->loadModels([]);
        $db->schema->invalidate();

        return $db;
    }

    public function testNodeDatabaseIsUnchangedForThePhpPort(): void
    {
        $db = $this->open();

        self::assertSame('17dfd27f1ec5cfbfdd62a2554476ae7a7519bfe36abf3bdf131e8d66ea8e1868', $db->schema->schemaStorage->read()['hash']);
        self::assertSame($db->schema->schemaStorage->read()['hash'], $db->schema->schemaStorage->hashSchema($db->schema->getSchema()), 'same hash as Node');
        self::assertFalse($db->migrations->shouldRun(), 'internal migrations are already logged');
        self::assertSame('UNCHANGED', $db->schema->sync());

        // even a full 3-way diff against the live Knex-made tables finds nothing to do
        self::assertSame('UNCHANGED', $db->schema->syncSchema());

        $row = $db->query('api::kitchensink.kitchensink')->findOne(['where' => ['short_text' => 'from-node']]);
        self::assertSame('abcdefghijklmnopqrstuvwx', $row['documentId']);
        self::assertSame(7, $row['integer']);
        self::assertSame('123456789012345', $row['biginteger']);
        self::assertSame(1.5, $row['decimal']);
        self::assertSame('2024-01-02', $row['date']);
        self::assertSame('2024-01-02T03:04:05.678Z', $row['datetime']);
        self::assertSame('03:04:05.000', $row['time']);
        self::assertSame('1704164645000', $row['timestamp']);
        self::assertTrue($row['boolean']);
        self::assertSame(['a' => 1], $row['json']);
        self::assertSame('2024-01-02T03:04:05.000Z', $row['publishedAt']);

        // and what PHP writes, Knex reads back the same way (epoch millis / 0-1 / JSON strings)
        $created = $db->query('api::kitchensink.kitchensink')->create(['data' => ['short_text' => 'from-php', 'datetime' => '2024-05-06T07:08:09.000Z', 'boolean' => false, 'json' => ['b' => 2]]]);
        $raw = $db->connection->fetchAssociative('select datetime, boolean, json, created_at from kitchensinks where id = ?', [$created['id']]);
        self::assertSame(1714979289000, (int) $raw['datetime']);
        self::assertSame(0, (int) $raw['boolean']);
        self::assertSame('{"b":2}', $raw['json']);
        self::assertIsNumeric($raw['created_at']);

        $db->destroy();
    }

    /**
     * The DDL this port generates with DBAL yields the same SQLite catalog as the DDL Knex generates
     * (fixtures/getstarted-sqlite.json: pragma table_info/index_list/foreign_key_list from the Knex database).
     */
    public function testDbalDdlMatchesKnexDdl(): void
    {
        $expected = json_decode((string) file_get_contents(__DIR__ . '/fixtures/getstarted-sqlite.json'), true, 512, JSON_THROW_ON_ERROR);
        $db = GetstartedDatabase::create();
        $db->schema->sync();

        $normalizeDefault = static fn (mixed $v): ?string => $v === null || strtoupper((string) $v) === 'NULL' ? null : (string) $v;

        foreach ($expected as $table => $info) {
            $columns = $db->connection->fetchAllAssociative('pragma table_info(' . $db->connection->quoteSingleIdentifier($table) . ')');
            self::assertCount(count($info['columns']), $columns, "columns of {$table}");
            foreach ($info['columns'] as $i => $column) {
                self::assertSame($column['name'], $columns[$i]['name'], "{$table} column {$i}");
                self::assertSame(strtolower($column['type']), strtolower((string) $columns[$i]['type']), "{$table}.{$column['name']} type");
                self::assertSame((int) $column['notnull'], (int) $columns[$i]['notnull'], "{$table}.{$column['name']} notnull");
                self::assertSame((int) $column['pk'], (int) $columns[$i]['pk'], "{$table}.{$column['name']} pk");
                self::assertSame($normalizeDefault($column['dflt_value']), $normalizeDefault($columns[$i]['dflt_value']), "{$table}.{$column['name']} default");
            }

            $indexes = array_values(array_filter($db->connection->fetchAllAssociative('pragma index_list(' . $db->connection->quoteSingleIdentifier($table) . ')'), static fn (array $i): bool => !str_starts_with((string) $i['name'], 'sqlite_')));
            $actualIndexes = array_map(static fn (array $i): array => ['name' => (string) $i['name'], 'unique' => (bool) $i['unique']], $indexes);
            $expectedIndexes = $info['indexes'];
            usort($actualIndexes, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            usort($expectedIndexes, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
            self::assertSame($expectedIndexes, $actualIndexes, "indexes of {$table}");

            $fk = static fn (array $f): array => [(string) $f['table'], (string) $f['from'], (string) $f['to'], (string) $f['on_delete']];
            $actualFks = array_map($fk, $db->connection->fetchAllAssociative('pragma foreign_key_list(' . $db->connection->quoteSingleIdentifier($table) . ')'));
            $expectedFks = array_map($fk, $info['fks']);
            sort($actualFks);
            sort($expectedFks);
            self::assertSame($expectedFks, $actualFks, "foreign keys of {$table}");
        }

        $db->destroy();
    }
}
