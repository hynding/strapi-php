<?php

declare(strict_types=1);

namespace Strapi\Database\Tests\Query;

use PHPUnit\Framework\TestCase;
use Strapi\Database\Database;
use Strapi\Database\Tests\Support\GetstartedDatabase;

/** Port of the pagination-stability and status-sort cases of __tests__/index.test.ts and the deleteMany/updateMany pick-set tests. */
final class QueryBuilderTest extends TestCase
{
    private static ?Database $db = null;

    private static function db(): Database
    {
        if (self::$db === null) {
            self::$db = GetstartedDatabase::create();
            self::$db->schema->sync();
        }

        return self::$db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->destroy();
        self::$db = null;
    }

    public function testAppendsPrimaryKeyToOrderByWhenPaginated(): void
    {
        $sql = self::db()->queryBuilder('api::article.article')->init(['orderBy' => ['title' => 'asc'], 'limit' => 10, 'offset' => 5])->toSql()['sql'];
        self::assertStringContainsString('ORDER BY "t0"."title" ASC, "t0"."id" ASC LIMIT 10 OFFSET 5', $sql);

        $sql = self::db()->queryBuilder('api::article.article')->init(['limit' => 10])->toSql()['sql'];
        self::assertStringContainsString('ORDER BY "t0"."id" ASC LIMIT 10', $sql);

        $sql = self::db()->queryBuilder('api::article.article')->init(['orderBy' => ['title' => 'asc', 'id' => 'desc'], 'limit' => 10])->toSql()['sql'];
        self::assertStringContainsString('ORDER BY "t0"."title" ASC, "t0"."id" DESC LIMIT 10', $sql);
        self::assertSame(1, substr_count($sql, '"t0"."id" DESC'));

        $sql = self::db()->queryBuilder('api::article.article')->init(['orderBy' => ['title' => 'asc']])->toSql()['sql'];
        self::assertStringEndsWith('ORDER BY "t0"."title" ASC', $sql, 'no pagination, no tie-break');

        $sql = self::db()->queryBuilder('api::article.article')->init(['limit' => -1])->toSql()['sql'];
        self::assertStringNotContainsString('ORDER BY', $sql);
    }

    public function testJoinOrderColumnsPrecedeRootTieBreak(): void
    {
        $qb = self::db()->queryBuilder('api::category.category');
        $alias = $qb->getAlias();
        $sql = $qb->init(['limit' => 10])
            ->join(['alias' => $alias, 'referencedTable' => 'articles_categories_lnk', 'referencedColumn' => 'category_id', 'rootColumn' => 'id', 'rootTable' => $qb->alias, 'orderBy' => ['category_ord' => 'asc']])
            ->toSql()['sql'];

        self::assertStringContainsString('ORDER BY "t1"."category_ord" ASC, "t0"."id" ASC', $sql);
        // order-by columns are added to the DISTINCT select (join order, then the id tie-break, then the selection)
        self::assertStringStartsWith('SELECT DISTINCT "t1"."category_ord", "t0"."id", "t0".*', $sql);
    }

    public function testStatusSort(): void
    {
        $sql = self::db()->queryBuilder('api::article.article')->init(['orderBy' => ['status' => 'desc'], 'limit' => 10])->toSql()['sql'];
        self::assertStringContainsString('ORDER BY CASE WHEN NOT EXISTS(SELECT 1 FROM "articles" sub WHERE sub.document_id = "t0".document_id AND sub.published_at IS NOT NULL AND sub.locale = "t0".locale) THEN 0', $sql);
        self::assertStringContainsString('THEN 1 ELSE 2 END DESC, "t0"."id" ASC LIMIT 10', $sql);

        // with a join (DISTINCT) the CASE expression is also selected
        $sql = self::db()->queryBuilder('api::article.article')->init(['orderBy' => 'status', 'where' => ['categories' => ['name' => 'x']], 'limit' => 10])->toSql()['sql'];
        self::assertStringStartsWith('SELECT DISTINCT "t0"."id", "t0".*, CASE WHEN NOT EXISTS', $sql);

        $this->expectExceptionMessage('Cannot order by status on model basic.simple: missing publishedAt or documentId');
        self::db()->queryBuilder('basic.simple')->init(['orderBy' => 'status'])->toSql();
    }

    public function testDeleteManyAndUpdateManyUseFilterParamsOnly(): void
    {
        $db = self::db();
        $tags = $db->query('api::tag.tag');
        $tags->createMany(['data' => [['name' => 'p1'], ['name' => 'p2'], ['name' => 'p3']]]);

        // limit/offset/orderBy/populate must be ignored
        self::assertSame(['count' => 3], $tags->updateMany(['where' => ['name' => ['$startsWith' => 'p']], 'data' => ['name' => 'px'], 'limit' => 1, 'offset' => 1, 'populate' => ['taggable'], 'orderBy' => 'name']));
        self::assertSame(['count' => 3], $tags->deleteMany(['where' => ['name' => 'px'], 'limit' => 1, 'populate' => '*']));
        self::assertSame(0, $tags->count());
    }

    public function testCreateManyReturnsIdsAndTimestamps(): void
    {
        $db = self::db();
        $tags = $db->query('api::tag.tag');
        $res = $tags->createMany(['data' => [['name' => 'm1'], ['name' => 'm2']]]);
        self::assertSame(2, $res['count']);
        self::assertCount(2, $res['ids']);
        $rows = $tags->findMany(['where' => ['id' => ['$in' => $res['ids']]], 'orderBy' => 'id']);
        self::assertSame(['m1', 'm2'], array_column($rows, 'name'));
        self::assertNotNull($rows[0]['createdAt']);
        $tags->deleteMany();
    }

    public function testAggregatesAndSelects(): void
    {
        $db = self::db();
        $tags = $db->query('api::tag.tag');
        $tags->createMany(['data' => [['name' => 'a'], ['name' => 'b']]]);

        $max = $db->queryBuilder('api::tag.tag')->max('id')->first()->execute();
        $min = $db->queryBuilder('api::tag.tag')->min('id')->first()->execute();
        self::assertGreaterThan((int) $min['min'], (int) $max['max']);

        $rows = $db->queryBuilder('api::tag.tag')->select(['id', 'name'])->where(['name' => ['$in' => ['a', 'b']]])->orderBy('name:desc')->execute();
        self::assertSame(['b', 'a'], array_column($rows, 'name'));
        self::assertSame(['id', 'name'], array_keys($rows[0]));

        $first = $db->queryBuilder('api::tag.tag')->select('name')->where(['name' => 'a'])->first()->execute();
        self::assertSame(['name' => 'a'], $first);

        self::assertSame(2, $db->queryBuilder('api::tag.tag')->where(['name' => ['$in' => ['a', 'b']]])->delete()->execute());
    }
}
