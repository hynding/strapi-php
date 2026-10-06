<?php

declare(strict_types=1);

namespace Strapi\Database\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Strapi\Database\Schema\Diff;
use Strapi\Database\Tests\Support\GetstartedDatabase;

/** Port of schema/__tests__/schema-diff.test.ts (representative cases) over a real SQLite dialect. */
final class DiffTest extends TestCase
{
    private Diff $diff;

    protected function setUp(): void
    {
        $this->diff = new Diff(GetstartedDatabase::create([], []));
    }

    private static function table(string $name, array $columns = [], array $indexes = [], array $foreignKeys = []): array
    {
        return ['name' => $name, 'columns' => $columns, 'indexes' => $indexes, 'foreignKeys' => $foreignKeys];
    }

    private static function emptyTableDiff(string $name): array
    {
        $empty = ['added' => [], 'updated' => [], 'unchanged' => [], 'removed' => []];

        return ['name' => $name, 'indexes' => $empty, 'foreignKeys' => $empty, 'columns' => $empty];
    }

    public function testNewTable(): void
    {
        $t = self::table('my_table');
        $result = $this->diff->diff(['databaseSchema' => ['tables' => []], 'userSchema' => ['tables' => [$t]], 'previousSchema' => ['tables' => [$t]]]);

        self::assertSame(['status' => 'CHANGED', 'diff' => ['tables' => ['added' => [$t], 'updated' => [], 'unchanged' => [], 'removed' => []]]], $result);
    }

    public function testRemovedTable(): void
    {
        $t = self::table('my_table');
        $result = $this->diff->diff(['databaseSchema' => ['tables' => [$t]], 'userSchema' => ['tables' => []], 'previousSchema' => ['tables' => [$t]]]);

        self::assertSame(['status' => 'CHANGED', 'diff' => ['tables' => ['added' => [], 'updated' => [], 'unchanged' => [], 'removed' => [$t]]]], $result);
    }

    public function testUnchangedTable(): void
    {
        $t = self::table('my_table');
        $result = $this->diff->diff(['databaseSchema' => ['tables' => [$t]], 'userSchema' => ['tables' => [$t]], 'previousSchema' => ['tables' => [$t]]]);

        self::assertSame('UNCHANGED', $result['status']);
        self::assertSame([$t], $result['diff']['tables']['unchanged']);
    }

    public function testUntrackedTableIsLeftAlone(): void
    {
        $t = self::table('user_table');
        $result = $this->diff->diff(['databaseSchema' => ['tables' => [$t]], 'userSchema' => ['tables' => []], 'previousSchema' => ['tables' => []]]);

        self::assertSame('UNCHANGED', $result['status']);
        self::assertSame([], $result['diff']['tables']['removed']);
    }

    public function testAddedColumn(): void
    {
        $column = ['name' => 'test_column', 'type' => 'text', 'notNullable' => true];
        $result = $this->diff->diff([
            'databaseSchema' => ['tables' => [self::table('my_table')]],
            'userSchema' => ['tables' => [self::table('my_table', [$column])]],
            'previousSchema' => ['tables' => [self::table('my_table', [$column])]],
        ]);

        $expected = self::emptyTableDiff('my_table');
        $expected['columns']['added'] = [$column];
        self::assertSame('CHANGED', $result['status']);
        self::assertEquals([$expected], $result['diff']['tables']['updated']);
    }

    public function testRemovedColumnOnlyWhenTracked(): void
    {
        $column = ['name' => 'old', 'type' => 'text', 'notNullable' => false, 'defaultTo' => null, 'unsigned' => false];
        $db = self::table('my_table', [$column]);
        $user = self::table('my_table');

        $untracked = $this->diff->diff(['databaseSchema' => ['tables' => [$db]], 'userSchema' => ['tables' => [$user]], 'previousSchema' => ['tables' => [$user]]]);
        self::assertSame('UNCHANGED', $untracked['status']);

        $tracked = $this->diff->diff(['databaseSchema' => ['tables' => [$db]], 'userSchema' => ['tables' => [$user]], 'previousSchema' => ['tables' => [$db]]]);
        self::assertSame('CHANGED', $tracked['status']);
        self::assertSame([$column], $tracked['diff']['tables']['updated'][0]['columns']['removed']);
    }

    public function testUpdatedColumn(): void
    {
        $old = ['name' => 'c', 'type' => 'text', 'notNullable' => false, 'defaultTo' => null, 'unsigned' => false];
        $new = ['name' => 'c', 'type' => 'string', 'notNullable' => false, 'defaultTo' => null, 'unsigned' => false];
        $result = $this->diff->diff([
            'databaseSchema' => ['tables' => [self::table('t', [$old])]],
            'userSchema' => ['tables' => [self::table('t', [$new])]],
            'previousSchema' => ['tables' => [self::table('t', [$old])]],
        ]);
        self::assertSame([['name' => 'c', 'object' => $new]], $result['diff']['tables']['updated'][0]['columns']['updated']);

        // default 'NULL' string (as sqlite reports it) equals null; unsigned ignored when unsupported
        $same = ['name' => 'c', 'type' => 'text', 'notNullable' => false, 'defaultTo' => 'NULL', 'unsigned' => true];
        $result = $this->diff->diff([
            'databaseSchema' => ['tables' => [self::table('t', [$same])]],
            'userSchema' => ['tables' => [self::table('t', [$old])]],
            'previousSchema' => ['tables' => [self::table('t', [$old])]],
        ]);
        self::assertSame('UNCHANGED', $result['status']);
    }

    public function testIncrementsTypeIsIgnoredAndDialectTypeMapping(): void
    {
        $dbColumn = ['name' => 'id', 'type' => 'integer', 'notNullable' => true, 'defaultTo' => null, 'unsigned' => false];
        $userColumn = ['name' => 'id', 'type' => 'increments', 'notNullable' => true, 'defaultTo' => null, 'unsigned' => false];
        $result = $this->diff->diff([
            'databaseSchema' => ['tables' => [self::table('t', [$dbColumn])]],
            'userSchema' => ['tables' => [self::table('t', [$userColumn])]],
            'previousSchema' => null,
        ]);
        self::assertSame('UNCHANGED', $result['status']);

        // sqlite reports `float` for `double`
        $dbColumn = ['name' => 'f', 'type' => 'float', 'notNullable' => false, 'defaultTo' => null, 'unsigned' => false];
        $userColumn = ['name' => 'f', 'type' => 'double', 'notNullable' => false, 'defaultTo' => null, 'unsigned' => false];
        $result = $this->diff->diff([
            'databaseSchema' => ['tables' => [self::table('t', [$dbColumn])]],
            'userSchema' => ['tables' => [self::table('t', [$userColumn])]],
            'previousSchema' => null,
        ]);
        self::assertSame('UNCHANGED', $result['status']);
    }

    public function testUpdatedIndex(): void
    {
        $old = ['name' => 'test_index', 'columns' => ['column1'], 'type' => 'unique'];
        $new = ['name' => 'test_index', 'columns' => ['column1', 'column2'], 'type' => 'unique'];
        $result = $this->diff->diff([
            'databaseSchema' => ['tables' => [self::table('my_table', [], [$old])]],
            'userSchema' => ['tables' => [self::table('my_table', [], [$new])]],
            'previousSchema' => ['tables' => [self::table('my_table', [], [$old])]],
        ]);

        self::assertSame('CHANGED', $result['status']);
        self::assertSame([['name' => 'test_index', 'object' => $new]], $result['diff']['tables']['updated'][0]['indexes']['updated']);

        // column order ignored
        $reordered = ['name' => 'test_index', 'columns' => ['column2', 'column1'], 'type' => 'unique'];
        $result = $this->diff->diff([
            'databaseSchema' => ['tables' => [self::table('my_table', [], [$new])]],
            'userSchema' => ['tables' => [self::table('my_table', [], [$reordered])]],
            'previousSchema' => ['tables' => [self::table('my_table', [], [$new])]],
        ]);
        self::assertSame('UNCHANGED', $result['status']);
    }

    public function testForeignKeysIgnoredWhenDialectDoesNotUseThem(): void
    {
        // SQLite: usesForeignKeys() is false, so fk changes never produce a diff
        $old = ['name' => 'fk_test', 'columns' => ['column1'], 'referencedTable' => 'another_table', 'referencedColumns' => ['id'], 'onDelete' => 'CASCADE', 'onUpdate' => 'RESTRICT'];
        $new = [...$old, 'onDelete' => 'RESTRICT', 'onUpdate' => 'CASCADE'];
        $result = $this->diff->diff([
            'databaseSchema' => ['tables' => [self::table('my_table', [], [], [$old])]],
            'userSchema' => ['tables' => [self::table('my_table', [], [], [$new])]],
            'previousSchema' => ['tables' => [self::table('my_table', [], [], [$old])]],
        ]);
        self::assertSame('UNCHANGED', $result['status']);

        // but the comparison itself detects the change (used by MySQL/PostgreSQL)
        self::assertSame('CHANGED', $this->diff->diffForeignKeys($old, $new)['status']);
        self::assertSame('UNCHANGED', $this->diff->diffForeignKeys($old, $old)['status']);
        // upstream quirk kept: a null action stays equal to a null action, and 'NO ACTION' to 'NO ACTION'
        self::assertSame('UNCHANGED', $this->diff->diffForeignKeys([...$old, 'onDelete' => null, 'onUpdate' => 'NO ACTION'], [...$old, 'onDelete' => null, 'onUpdate' => 'no action'])['status']);
    }

    public function testWithPersistedTables(): void
    {
        $t0 = self::table('my_table');
        $t1 = self::table('my_table_1');
        $coreStore = self::table('strapi_core_store_settings');

        $this->diff->setPersistedTablesProvider(static fn (): array => ['my_table', 'table2']);

        $result = $this->diff->diff([
            'databaseSchema' => ['tables' => [$t0, $t1, $coreStore]],
            'userSchema' => ['tables' => [$coreStore]],
            'previousSchema' => ['tables' => [$coreStore, $t1]],
        ]);

        self::assertSame(['status' => 'CHANGED', 'diff' => ['tables' => ['added' => [], 'updated' => [], 'unchanged' => [$coreStore], 'removed' => [$t1]]]], $result);
    }

    public function testReservedTablesAreNeverRemoved(): void
    {
        $reserved = self::table('strapi_migrations');
        $result = $this->diff->diff([
            'databaseSchema' => ['tables' => [$reserved]],
            'userSchema' => ['tables' => []],
            'previousSchema' => ['tables' => [$reserved]],
        ]);
        self::assertSame('UNCHANGED', $result['status']);
    }
}
