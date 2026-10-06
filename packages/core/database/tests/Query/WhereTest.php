<?php

declare(strict_types=1);

namespace Strapi\Database\Tests\Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Database\Database;
use Strapi\Database\Query\Helpers\Where;
use Strapi\Database\Query\SqlBuilder;
use Strapi\Database\Tests\Support\GetstartedDatabase;

/** Port of query/helpers/__tests__/where.test.ts (compiled SQL only, sqlite dialect) plus processWhere cases. */
final class WhereTest extends TestCase
{
    private static ?Database $db = null;

    private static function db(): Database
    {
        return self::$db ??= GetstartedDatabase::create();
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->destroy();
        self::$db = null;
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    private static function compile(array $where): array
    {
        $qb = self::db()->sql()->from('t');
        Where::applyWhere($qb, $where);

        return $qb->toSql();
    }

    #[DataProvider('inOperators')]
    public function testInKeepsSnapshotOfValues(string $operator): void
    {
        $values = [1, 2];
        $qb = self::db()->sql()->from('t');
        Where::applyWhere($qb, ['id' => [$operator => $values]]);
        $values[] = 3;
        self::assertSame([1, 2], $qb->toSql()['bindings']);
    }

    /** @return iterable<array{string}> */
    public static function inOperators(): iterable
    {
        yield ['$in'];
        yield ['$notIn'];
    }

    public function testEqiCompilesToCaseInsensitiveEquality(): void
    {
        ['sql' => $sql, 'bindings' => $bindings] = self::compile(['handle' => ['$eqi' => 'a_c']]);
        self::assertStringContainsString('= LOWER(', $sql);
        self::assertDoesNotMatchRegularExpression('/LIKE/i', $sql);
        self::assertDoesNotMatchRegularExpression('/ESCAPE/i', $sql);
        self::assertContains('a_c', $bindings);
    }

    public function testNeiCompilesToCaseInsensitiveInequality(): void
    {
        ['sql' => $sql, 'bindings' => $bindings] = self::compile(['handle' => ['$nei' => 'a_c']]);
        self::assertStringContainsString('<> LOWER(', $sql);
        self::assertContains('a_c', $bindings);
    }

    public function testContainsiEscapes(): void
    {
        ['bindings' => $bindings] = self::compile(['handle' => ['$containsi' => '50%_a\\b']]);
        self::assertContains('%50\\%\\_a\\\\b%', $bindings);
    }

    public function testStartsWithAndEndsWith(): void
    {
        ['sql' => $sql, 'bindings' => $bindings] = self::compile(['handle' => ['$startsWith' => '100%']]);
        self::assertMatchesRegularExpression('/LIKE/i', $sql);
        self::assertContains('100\\%%', $bindings);

        ['bindings' => $bindings] = self::compile(['handle' => ['$endsWith' => 'a_c']]);
        self::assertContains('%a\\_c', $bindings);

        ['bindings' => $bindings] = self::compile(['handle' => ['$endsWith' => '100%']]);
        self::assertContains('%100\\%', $bindings);
        self::assertNotContains('%100%', $bindings);

        ['sql' => $sql, 'bindings' => $bindings] = self::compile(['handle' => ['$notContains' => '%']]);
        self::assertMatchesRegularExpression('/NOT LIKE/i', $sql);
        self::assertContains('%\\%%', $bindings);
    }

    public function testSqliteAddsEscapeClauseAndNoCast(): void
    {
        ['sql' => $sql] = self::compile(['handle' => ['$containsi' => 'x']]);
        self::assertStringContainsString("ESCAPE '\\'", $sql);
        self::assertMatchesRegularExpression('/LOWER\(/i', $sql);
        self::assertDoesNotMatchRegularExpression('/CAST/i', $sql);
    }

    public function testGroupsNullsBetweenAndNot(): void
    {
        ['sql' => $sql, 'bindings' => $bindings] = self::compile([
            '$or' => [['a' => 1], ['a' => ['$gt' => 2]]],
            '$and' => [['b' => ['$null' => true]], ['c' => ['$notNull' => true]]],
            '$not' => ['d' => ['$between' => [1, 5]]],
            'e' => [1, 2, 3],
            'f' => null,
            'g' => ['$ne' => null],
            'h' => ['$lte' => 4],
        ]);

        self::assertSame(
            '(("a" = ?) OR ("a" > ?)) AND ("b" IS NULL AND "c" IS NOT NULL) AND NOT ("d" BETWEEN ? AND ?) AND "e" IN (?, ?, ?) AND "f" IS NULL AND "g" IS NOT NULL AND "h" <= ?',
            substr($sql, strpos($sql, 'WHERE ') + 6),
        );
        self::assertSame([1, 2, 1, 5, 1, 2, 3, 4], $bindings);
    }

    public function testArrayValueOnNonArrayOperatorBecomesOr(): void
    {
        ['sql' => $sql, 'bindings' => $bindings] = self::compile(['a' => ['$contains' => ['x', 'y']]]);
        self::assertStringContainsString('(("a" LIKE ? ESCAPE \'\\\') OR ("a" LIKE ? ESCAPE \'\\\'))', $sql);
        self::assertSame(['%x%', '%y%'], $bindings);
    }

    public function testProcessWhereCastsAndJoinsRelations(): void
    {
        $qb = self::db()->queryBuilder('api::kitchensink.kitchensink');
        $where = Where::processWhere([
            'short_text' => 'x',
            'boolean' => 'true',
            'integer' => ['$in' => ['1', '2']],
            'one_way_tag' => ['name' => ['$containsi' => 'a']],
            'many_to_many_tags' => 5,
            'unknown' => 'kept',
        ], $qb, 'api::kitchensink.kitchensink');

        self::assertSame('x', $where['t0.short_text']);
        self::assertTrue($where['t0.boolean']);
        self::assertSame(['$in' => [1, 2]], $where['t0.integer']);
        self::assertSame(['$containsi' => 'a'], $where['t2.name']);
        self::assertSame(5, $where['t4.id']);
        self::assertSame('kept', $where['t0.unknown']);
        self::assertCount(4, $qb->state['joins']);
        self::assertSame('kitchensinks_one_way_tag_lnk', $qb->state['joins'][0]['referencedTable']);
        self::assertSame('tags', $qb->state['joins'][1]['referencedTable']);
    }

    public function testProcessWhereRejectsBadOperators(): void
    {
        $qb = self::db()->queryBuilder('api::tag.tag');
        $this->expectExceptionMessage('Only $and, $or and $not can only be used as root level operators. Found $eq.');
        Where::processWhere(['$eq' => 1], $qb, 'api::tag.tag');
    }

    public function testProcessWhereRejectsMixedRelationKeys(): void
    {
        $qb = self::db()->queryBuilder('api::kitchensink.kitchensink');
        $this->expectExceptionMessage('Operator and non-operator keys cannot be mixed in a relation where clause');
        Where::processWhere(['one_way_tag' => ['$in' => [1], 'name' => 'x']], $qb, 'api::kitchensink.kitchensink');
    }

    public function testQualifyRootColumns(): void
    {
        self::assertSame(
            ['t0.published_at' => null, '$or' => [['t0.a' => 1], ['t1.b' => 2]], '$not' => ['t0.c' => 3], 't9.d' => 4],
            Where::qualifyRootColumns(['published_at' => null, '$or' => [['a' => 1], ['t1.b' => 2]], '$not' => ['c' => 3], 't9.d' => 4], 't0'),
        );
    }

    public function testUpdateWithRelationFilterUsesSubQuery(): void
    {
        $sql = self::db()->queryBuilder('api::kitchensink.kitchensink')
            ->where(['one_way_tag' => ['name' => 'x'], 'short_text' => 'y'])
            ->update(['long_text' => 'z'])
            ->toSql();

        self::assertStringStartsWith('UPDATE "kitchensinks" SET "long_text" = ? WHERE "id" IN (SELECT "id" FROM (SELECT DISTINCT "t0"."id" FROM "kitchensinks" AS "t0"', $sql['sql']);
        self::assertStringContainsString('"t0"."short_text" = ?', $sql['sql']);
        self::assertSame(['z', 'x', 'y'], $sql['bindings']);
    }
}
