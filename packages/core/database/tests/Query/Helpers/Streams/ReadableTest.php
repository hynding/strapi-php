<?php

declare(strict_types=1);

namespace Strapi\Database\Tests\Query\Helpers\Streams;

use PHPUnit\Framework\TestCase;
use Strapi\Database\Database;
use Strapi\Database\Errors\DatabaseError;
use Strapi\Database\Query\Helpers\Streams\Readable;
use Strapi\Database\Tests\Support\GetstartedDatabase;
use Strapi\Database\Utils\Knex;

/** query/helpers/streams/readable.ts, utils/knex.ts (no upstream unit tests). */
final class ReadableTest extends TestCase
{
    private static ?Database $db = null;

    private static function db(): Database
    {
        if (self::$db === null) {
            self::$db = GetstartedDatabase::create();
            self::$db->schema->sync();
            for ($i = 1; $i <= 7; $i++) {
                self::$db->query('api::category.category')->create(['data' => ['name' => "c{$i}", 'documentId' => "doc{$i}"]]);
            }
        }

        return self::$db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->destroy();
        self::$db = null;
    }

    public function testStreamsEveryRowInBatches(): void
    {
        $stream = new Readable(self::db()->queryBuilder('api::category.category')->select(['id', 'name'])->orderBy(['id' => 'asc']), self::db(), 'api::category.category', true, 3);

        $names = array_map(static fn (array $row): mixed => $row['name'], iterator_to_array($stream, false));

        self::assertSame(['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7'], $names);
        self::assertSame(7, $stream->fetched);
        self::assertNull($stream->read(3), 'an ended stream reads nothing');
    }

    public function testHonoursTheQueryOffsetAndLimit(): void
    {
        $qb = self::db()->queryBuilder('api::category.category')->select(['id', 'name'])->orderBy(['id' => 'asc'])->offset(1)->limit(4);
        $stream = new Readable($qb, self::db(), 'api::category.category', true, 3);

        self::assertSame(['c2', 'c3', 'c4'], array_column($stream->read(3) ?? [], 'name'));
        self::assertSame(['c5'], array_column($stream->read(3) ?? [], 'name'), 'only the rows left under the limit');
        self::assertNull($stream->read(3));
    }

    public function testQueryBuilderStream(): void
    {
        $rows = iterator_to_array(self::db()->queryBuilder('api::category.category')->select(['id', 'name'])->limit(2)->stream(), false);
        self::assertCount(2, $rows);

        $this->expectException(DatabaseError::class);
        $this->expectExceptionMessage('query-builder.stream() has been called with an unsupported query type: "delete"');
        self::db()->queryBuilder('api::category.category')->delete()->stream();
    }

    public function testKnexHelpers(): void
    {
        $db = self::db();
        self::assertTrue(Knex::isKnexQuery($db->sql()->from('categories')));
        self::assertTrue(Knex::isKnexQuery($db->queryBuilder('api::category.category')->raw('1')));
        self::assertFalse(Knex::isKnexQuery('categories'));
        self::assertSame('categories', Knex::addSchema($db, 'categories'));
    }
}
