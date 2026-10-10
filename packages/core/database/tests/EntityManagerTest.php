<?php

declare(strict_types=1);

namespace Strapi\Database\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Database\Database;
use Strapi\Database\Tests\Support\GetstartedDatabase;

/**
 * Integration test over the getstarted schemas on in-memory SQLite: scalar fields, relations
 * (connect/disconnect/set and ordering), components, dynamic zones, filters, sort, pagination,
 * populate and lifecycles.
 */
final class EntityManagerTest extends TestCase
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

    public function testCreateAndFindScalars(): void
    {
        $db = self::db();

        $entry = $db->query('api::kitchensink.kitchensink')->create(['data' => [
            'short_text' => 'hello',
            'long_text' => 'a long text',
            'integer' => '42',
            'biginteger' => '9007199254740993',
            'decimal' => 1.5,
            'float' => '2.25',
            'date' => '2024-03-04',
            'datetime' => '2024-03-04T10:11:12.123Z',
            'time' => '10:11:12',
            'timestamp' => '2024-03-04T10:11:12.000Z',
            'boolean' => 'true',
            'json' => ['a' => 1, 'b' => [1, 2]],
            'blocks' => [['type' => 'paragraph', 'children' => [['type' => 'text', 'text' => 'hi']]]],
            'enumeration' => 'A',
            'email' => 'a@b.c',
            'publishedAt' => '2024-03-04T10:11:12.000Z',
        ]]);

        self::assertIsInt($entry['id']);
        self::assertMatchesRegularExpression('/^[a-z][a-z0-9]{23}$/', $entry['documentId']);
        self::assertSame('hello', $entry['short_text']);
        self::assertSame(42, $entry['integer']);
        self::assertSame('9007199254740993', $entry['biginteger']);
        self::assertSame(1.5, $entry['decimal']);
        self::assertSame(2.25, $entry['float']);
        self::assertSame('2024-03-04', $entry['date']);
        self::assertSame('2024-03-04T10:11:12.123Z', $entry['datetime']);
        self::assertSame('10:11:12.000', $entry['time']);
        self::assertSame('1709547072000', $entry['timestamp']);
        self::assertTrue($entry['boolean']);
        self::assertSame(['a' => 1, 'b' => [1, 2]], $entry['json']);
        self::assertSame('paragraph', $entry['blocks'][0]['type']);
        self::assertSame('A', $entry['enumeration']);
        self::assertNotNull($entry['createdAt']);
        self::assertNotNull($entry['updatedAt']);
        self::assertSame($entry['createdAt'], $entry['updatedAt']);
        self::assertSame('2024-03-04T10:11:12.000Z', $entry['publishedAt']);
        self::assertArrayNotHasKey('single_compo', $entry, 'relations are not populated by default');

        $found = $db->query('api::kitchensink.kitchensink')->findOne(['where' => ['short_text' => 'hello']]);
        self::assertSame($entry['id'], $found['id']);

        $updated = $db->query('api::kitchensink.kitchensink')->update(['where' => ['id' => $entry['id']], 'data' => ['short_text' => 'hello2', 'boolean' => false]]);
        self::assertSame('hello2', $updated['short_text']);
        self::assertFalse($updated['boolean']);
        self::assertSame($entry['createdAt'], $updated['createdAt']);

        self::assertSame(1, $db->query('api::kitchensink.kitchensink')->count(['where' => ['short_text' => ['$startsWith' => 'hell']]]));
        self::assertSame(0, $db->query('api::kitchensink.kitchensink')->count(['where' => ['short_text' => ['$eq' => 'nope']]]));

        $deleted = $db->query('api::kitchensink.kitchensink')->delete(['where' => ['id' => $entry['id']]]);
        self::assertSame($entry['id'], $deleted['id']);
        self::assertNull($db->query('api::kitchensink.kitchensink')->findOne(['where' => ['id' => $entry['id']]]));
    }

    public function testManyToManyConnectDisconnectSetAndOrdering(): void
    {
        $db = self::db();
        $categories = $db->query('api::category.category');
        $articles = $db->query('api::article.article');

        $c1 = $categories->create(['data' => ['name' => 'c1']]);
        $c2 = $categories->create(['data' => ['name' => 'c2']]);
        $c3 = $categories->create(['data' => ['name' => 'c3']]);

        // plain array of ids on create
        $article = $articles->create(['data' => ['title' => 'a1', 'categories' => [$c2['id'], $c1['id']]], 'populate' => ['categories']]);
        self::assertSame(['c2', 'c1'], array_column($article['categories'], 'name'), 'set order is kept');

        // connect with position
        $article = $articles->update([
            'where' => ['id' => $article['id']],
            'data' => ['categories' => ['connect' => [['id' => $c3['id'], 'position' => ['before' => $c1['id']]]]]],
            'populate' => ['categories'],
        ]);
        self::assertSame(['c2', 'c3', 'c1'], array_column($article['categories'], 'name'));

        $orders = $db->connection->fetchAllAssociative('select category_id, category_ord from articles_categories_lnk where article_id = ? order by category_ord', [$article['id']]);
        self::assertSame([1.0, 2.0, 3.0], array_map(static fn (array $r): float => (float) $r['category_ord'], $orders), 'orders are compacted');

        // disconnect
        $article = $articles->update([
            'where' => ['id' => $article['id']],
            'data' => ['categories' => ['disconnect' => [['id' => $c2['id']]]]],
            'populate' => ['categories'],
        ]);
        self::assertSame(['c3', 'c1'], array_column($article['categories'], 'name'));

        // the inverse side
        $cat = $categories->findOne(['where' => ['id' => $c1['id']], 'populate' => ['articles']]);
        self::assertSame(['a1'], array_column($cat['articles'], 'title'));

        // connect at start
        $article = $articles->update([
            'where' => ['id' => $article['id']],
            'data' => ['categories' => ['connect' => [['id' => $c2['id'], 'position' => ['start' => true]]]]],
            'populate' => ['categories'],
        ]);
        self::assertSame(['c2', 'c3', 'c1'], array_column($article['categories'], 'name'));

        // set replaces
        $article = $articles->update([
            'where' => ['id' => $article['id']],
            'data' => ['categories' => ['set' => [['id' => $c1['id']]]]],
            'populate' => ['categories'],
        ]);
        self::assertSame(['c1'], array_column($article['categories'], 'name'));

        // documentId connect
        $article = $articles->update([
            'where' => ['id' => $article['id']],
            'data' => ['categories' => ['connect' => [['documentId' => $c3['documentId'], 'status' => 'draft']]]],
            'populate' => ['categories'],
        ]);
        self::assertSame(['c1', 'c3'], array_column($article['categories'], 'name'));

        // null clears
        $article = $articles->update(['where' => ['id' => $article['id']], 'data' => ['categories' => null], 'populate' => ['categories']]);
        self::assertSame([], $article['categories']);

        // count populate
        $articles->update(['where' => ['id' => $article['id']], 'data' => ['categories' => [$c1['id'], $c2['id']]]]);
        $withCount = $articles->findOne(['where' => ['id' => $article['id']], 'populate' => ['categories' => ['count' => true]]]);
        self::assertSame(['count' => 2], $withCount['categories']);

        // load / loadPages
        $loaded = $articles->load($article, 'categories', ['orderBy' => ['name' => 'desc']]);
        self::assertSame(['c2', 'c1'], array_column($loaded, 'name'));
        $pages = $articles->loadPages($article, 'categories', ['page' => 1, 'pageSize' => 1]);
        self::assertSame(2, $pages['pagination']['total']);
        self::assertCount(1, $pages['results']);

        // delete the article cleans the link table
        $articles->delete(['where' => ['id' => $article['id']]]);
        self::assertSame(0, (int) $db->connection->fetchOne('select count(*) from articles_categories_lnk where article_id = ?', [$article['id']]));
    }

    public function testOneToManyManyToOneAndJoinColumns(): void
    {
        $db = self::db();
        $tags = $db->query('api::tag.tag');
        $ks = $db->query('api::kitchensink.kitchensink');

        $t1 = $tags->create(['data' => ['name' => 't1']]);
        $t2 = $tags->create(['data' => ['name' => 't2']]);

        $k = $ks->create(['data' => [
            'short_text' => 'k1',
            'one_to_many_tags' => [$t1['id'], $t2['id']],
            'one_way_tag' => $t1['id'],
            'one_to_one_tag' => ['id' => $t2['id']],
            'many_way_tags' => [['id' => $t2['id']], ['id' => $t1['id']]],
        ], 'populate' => '*']);

        self::assertSame(['t1', 't2'], array_column($k['one_to_many_tags'], 'name'));
        self::assertSame('t1', $k['one_way_tag']['name']);
        self::assertSame('t2', $k['one_to_one_tag']['name']);
        self::assertSame(['t2', 't1'], array_column($k['many_way_tags'], 'name'));
        self::assertNull($k['many_to_one_tag']);
        self::assertSame([], $k['many_to_many_tags']);
        self::assertNull($k['single_compo']);
        self::assertSame([], $k['repeatable_compo']);
        self::assertSame([], $k['dynamiczone']);
        self::assertNull($k['createdBy']);

        // inverse side
        $tag = $tags->findOne(['where' => ['id' => $t1['id']], 'populate' => ['many_to_one_kitchensink', 'one_to_one_kitchensink']]);
        self::assertSame('k1', $tag['many_to_one_kitchensink']['short_text']);
        self::assertNull($tag['one_to_one_kitchensink']);

        $tag2 = $tags->findOne(['where' => ['id' => $t2['id']], 'populate' => ['one_to_one_kitchensink']]);
        self::assertSame('k1', $tag2['one_to_one_kitchensink']['short_text']);

        // manyToOne from the many side moves the relation (oneToMany: a tag belongs to one kitchensink)
        $k2 = $ks->create(['data' => ['short_text' => 'k2', 'one_to_many_tags' => ['connect' => [['id' => $t1['id']]]]], 'populate' => ['one_to_many_tags']]);
        self::assertSame(['t1'], array_column($k2['one_to_many_tags'], 'name'));
        $k1 = $ks->findOne(['where' => ['id' => $k['id']], 'populate' => ['one_to_many_tags']]);
        self::assertSame(['t2'], array_column($k1['one_to_many_tags'], 'name'), 'oneToMany steals the relation');

        // nested relation filter + sort on relation
        $found = $ks->findMany(['where' => ['one_to_many_tags' => ['name' => 't2']]]);
        self::assertSame(['k1'], array_column($found, 'short_text'));

        $found = $ks->findMany(['where' => ['one_to_many_tags' => ['name' => ['$in' => ['t1', 't2']]]], 'orderBy' => ['short_text' => 'desc']]);
        self::assertSame(['k2', 'k1'], array_column($found, 'short_text'));

        $ks->update(['where' => ['id' => $k2['id']], 'data' => ['one_way_tag' => $t2['id']]]);
        $found = $ks->findMany(['where' => ['short_text' => ['$startsWith' => 'k']], 'orderBy' => ['one_way_tag' => ['name' => 'desc']]]);
        self::assertSame(['k2', 'k1'], array_column($found, 'short_text'), 'deep sort');
        $found = $ks->findMany(['where' => ['short_text' => ['$startsWith' => 'k']], 'orderBy' => ['one_way_tag' => ['name' => 'asc']], 'limit' => 1]);
        self::assertSame(['k1'], array_column($found, 'short_text'), 'deep sort with limit');
        $ks->update(['where' => ['id' => $k2['id']], 'data' => ['one_way_tag' => null]]);

        $found = $ks->findMany(['where' => ['$or' => [['short_text' => 'k1'], ['short_text' => 'k2']], '$not' => ['short_text' => 'k1']]]);
        self::assertSame(['k2'], array_column($found, 'short_text'));

        $found = $ks->findMany(['where' => ['one_way_tag' => ['$null' => true]]]);
        self::assertSame(['k2'], array_column($found, 'short_text'));

        // pagination
        $page = $ks->findPage(['page' => 2, 'pageSize' => 1, 'orderBy' => 'short_text', 'where' => ['short_text' => ['$startsWith' => 'k']]]);
        self::assertSame(['page' => 2, 'pageSize' => 1, 'pageCount' => 2, 'total' => 2], $page['pagination']);
        self::assertSame('k2', $page['results'][0]['short_text']);

        // nested populate
        $tagWithK = $tags->findOne(['where' => ['id' => $t2['id']], 'populate' => ['many_to_one_kitchensink' => ['populate' => ['one_way_tag']]]]);
        self::assertSame('t1', $tagWithK['many_to_one_kitchensink']['one_way_tag']['name']);

        // search (_q) over every searchable string column (ids/document ids included, like upstream)
        $ks->update(['where' => ['id' => $k2['id']], 'data' => ['long_text' => 'Needle In The Haystack']]);
        $found = $ks->findMany(['_q' => 'needle in']);
        self::assertSame(['k2'], array_column($found, 'short_text'));

        // updateMany / deleteMany
        self::assertSame(['count' => 2], $ks->updateMany(['where' => ['short_text' => ['$startsWith' => 'k']], 'data' => ['long_text' => 'bulk']]));
        self::assertSame(2, $ks->count(['where' => ['long_text' => 'bulk']]));
        self::assertSame(['count' => 2], $ks->deleteMany(['where' => ['long_text' => 'bulk']]));
        self::assertSame(0, $ks->count());
    }

    public function testComponentsAndDynamicZones(): void
    {
        $db = self::db();
        $simples = $db->query('basic.simple');
        $ks = $db->query('api::kitchensink.kitchensink');

        // core creates component rows first then links them through the _cmps table
        $s1 = $simples->create(['data' => ['name' => 's1']]);
        $s2 = $simples->create(['data' => ['name' => 's2']]);
        $s3 = $simples->create(['data' => ['name' => 's3']]);
        $como = $db->query('blog.test-como')->create(['data' => ['name' => 'como']]);

        // the document service passes `__pivot: { field, component_type }` with every component link
        $compo = static fn (array $row, string $field, string $type): array => ['id' => $row['id'], '__pivot' => ['field' => $field, 'component_type' => $type]];

        $k = $ks->create(['data' => [
            'short_text' => 'with-compos',
            'single_compo' => $compo($s1, 'single_compo', 'basic.simple'),
            'repeatable_compo' => [$compo($s3, 'repeatable_compo', 'basic.simple'), $compo($s2, 'repeatable_compo', 'basic.simple')],
            'dynamiczone' => [['id' => $como['id'], '__component' => 'blog.test-como'], ['id' => $s2['id'], '__component' => 'basic.simple']],
        ], 'populate' => ['single_compo', 'repeatable_compo', 'dynamiczone']]);

        self::assertSame('s1', $k['single_compo']['name']);
        self::assertSame(['s3', 's2'], array_column($k['repeatable_compo'], 'name'));
        self::assertSame([['name' => 'como', '__component' => 'blog.test-como'], ['name' => 's2', '__component' => 'basic.simple']], array_map(static fn (array $c): array => ['name' => $c['name'], '__component' => $c['__component']], $k['dynamiczone']));

        $rows = $db->connection->fetchAllAssociative('select field, cmp_id, component_type, "order" from kitchensinks_cmps where entity_id = ? order by field, "order"', [$k['id']]);
        self::assertSame([
            ['field' => 'dynamiczone', 'cmp_id' => $como['id'], 'component_type' => 'blog.test-como', 'order' => 1.0],
            ['field' => 'dynamiczone', 'cmp_id' => $s2['id'], 'component_type' => 'basic.simple', 'order' => 2.0],
            ['field' => 'repeatable_compo', 'cmp_id' => $s3['id'], 'component_type' => 'basic.simple', 'order' => 1.0],
            ['field' => 'repeatable_compo', 'cmp_id' => $s2['id'], 'component_type' => 'basic.simple', 'order' => 2.0],
            ['field' => 'single_compo', 'cmp_id' => $s1['id'], 'component_type' => 'basic.simple', 'order' => null],
        ], array_map(static fn (array $r): array => [...$r, 'cmp_id' => (int) $r['cmp_id'], 'order' => $r['order'] === null ? null : (float) $r['order']], $rows));

        // reorder the repeatable component and replace the dz
        $k = $ks->update(['where' => ['id' => $k['id']], 'data' => [
            'repeatable_compo' => [$compo($s2, 'repeatable_compo', 'basic.simple'), $compo($s3, 'repeatable_compo', 'basic.simple')],
            'dynamiczone' => [['id' => $s1['id'], '__component' => 'basic.simple']],
        ], 'populate' => ['repeatable_compo', 'dynamiczone']]);
        self::assertSame(['s2', 's3'], array_column($k['repeatable_compo'], 'name'));
        self::assertSame(['s1'], array_column($k['dynamiczone'], 'name'));

        // populate '*' with nested populate on the component
        $all = $ks->findOne(['where' => ['id' => $k['id']], 'populate' => '*']);
        self::assertSame('s1', $all['single_compo']['name']);
        self::assertArrayHasKey('cats', $all);
    }

    public function testMediaMorphRelationsAndLifecycles(): void
    {
        $db = self::db();
        $files = $db->query('plugin::upload.file');
        $articles = $db->query('api::article.article');

        $f1 = $files->create(['data' => ['name' => 'f1.png', 'hash' => 'f1', 'mime' => 'image/png', 'size' => 1.2, 'url' => '/f1.png', 'provider' => 'local', 'folderPath' => '/']]);
        $f2 = $files->create(['data' => ['name' => 'f2.png', 'hash' => 'f2', 'mime' => 'image/png', 'size' => 2.5, 'url' => '/f2.png', 'provider' => 'local', 'folderPath' => '/']]);

        $article = $articles->create(['data' => ['title' => 'with-cover', 'cover' => $f1['id']], 'populate' => ['cover']]);
        self::assertSame('f1.png', $article['cover']['name']);

        $rows = $db->connection->fetchAllAssociative('select file_id, related_id, related_type, field, "order" from files_related_mph');
        self::assertSame([['file_id' => $f1['id'], 'related_id' => $article['id'], 'related_type' => 'api::article.article', 'field' => 'cover', 'order' => 1.0]], array_map(static fn (array $r): array => [...$r, 'file_id' => (int) $r['file_id'], 'related_id' => (int) $r['related_id'], 'order' => (float) $r['order']], $rows));

        // the file side: `related` is morphToMany
        $file = $files->findOne(['where' => ['id' => $f1['id']], 'populate' => ['related']]);
        self::assertSame('with-cover', $file['related'][0]['title']);
        self::assertSame('api::article.article', $file['related'][0]['__type']);

        $article = $articles->update(['where' => ['id' => $article['id']], 'data' => ['cover' => $f2['id']], 'populate' => ['cover']]);
        self::assertSame('f2.png', $article['cover']['name']);
        self::assertSame(1, (int) $db->connection->fetchOne('select count(*) from files_related_mph'));

        $address = $db->query('api::address.address')->create(['data' => ['city' => 'Paris', 'images' => [$f1['id'], $f2['id']]], 'populate' => ['images']]);
        self::assertSame(['f1.png', 'f2.png'], array_column($address['images'], 'name'));

        // lifecycles
        $events = [];
        $unsubscribe = $db->lifecycles->subscribe([
            'models' => ['api::article.article'],
            'beforeCreate' => function (\Strapi\Database\Lifecycles\Event $event) use (&$events): void {
                $events[] = $event->action;
                $event->state['seen'] = true;
                $event->params['data']['authorName'] = 'hooked';
            },
            'afterCreate' => function (\Strapi\Database\Lifecycles\Event $event) use (&$events): void {
                $events[] = $event->action . ':' . ($event->state['seen'] ? 'with-state' : 'no-state');
            },
        ]);
        $hooked = $articles->create(['data' => ['title' => 'hooked']]);
        self::assertSame('hooked', $hooked['authorName']);
        self::assertSame(['beforeCreate', 'afterCreate:with-state'], $events);
        $unsubscribe();
        $articles->create(['data' => ['title' => 'not-hooked']]);
        self::assertCount(2, $events);

        // model lifecycles from the schema config
        $db->query('api::article.article')->deleteMany();
    }

    public function testTransactionsAndErrors(): void
    {
        $db = self::db();
        $tags = $db->query('api::tag.tag');

        try {
            $db->transaction(static function () use ($tags): void {
                $tags->create(['data' => ['name' => 'in-trx']]);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertSame(0, $tags->count(['where' => ['name' => 'in-trx']]));

        $committed = [];
        $db->transaction(static function (array $ctx) use ($tags, &$committed): void {
            $ctx['onCommit'](static function () use (&$committed): void {
                $committed[] = 'yes';
            });
            $tags->create(['data' => ['name' => 'in-trx']]);
        });
        self::assertSame(1, $tags->count(['where' => ['name' => 'in-trx']]));
        self::assertSame(['yes'], $committed);

        // a nested transaction's callbacks run when the outer one ends (document-service events)
        $events = [];
        $db->transaction(static function () use ($db, &$events): void {
            $db->transaction(static function (array $ctx) use (&$events): void {
                $ctx['onCommit'](static function () use (&$events): void {
                    $events[] = 'commit';
                });
            });
            self::assertSame([], $events, 'not before the outer transaction commits');
        });
        self::assertSame(['commit'], $events);

        try {
            $db->transaction(static function () use ($db, &$events): void {
                $db->transaction(static function (array $ctx) use (&$events): void {
                    $ctx['onRollback'](static function () use (&$events): void {
                        $events[] = 'rollback';
                    });
                });
                throw new \RuntimeException('outer');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame(['commit', 'rollback'], $events);

        $trx = $db->transaction();
        $tags->create(['data' => ['name' => 'manual']]);
        $trx->rollback();
        self::assertSame(0, $tags->count(['where' => ['name' => 'manual']]));

        $this->expectException(\Strapi\Database\Errors\InvalidDateError::class);
        $db->query('api::kitchensink.kitchensink')->create(['data' => ['date' => 'not a date']]);
    }

    public function testQueryBuilderSqlCompilation(): void
    {
        $db = self::db();
        $sql = $db->queryBuilder('api::article.article')
            ->init([
                'select' => ['title'],
                'where' => ['title' => ['$containsi' => 'a_b'], 'categories' => ['name' => ['$in' => ['x']]]],
                'orderBy' => ['title' => 'desc'],
                'limit' => 5,
                'offset' => 10,
            ])
            ->toSql();

        self::assertStringContainsString('SELECT DISTINCT', $sql['sql']);
        self::assertStringContainsString('LEFT JOIN "articles_categories_lnk" AS "t1"', $sql['sql']);
        self::assertStringContainsString('LEFT JOIN "categories" AS "t2"', $sql['sql']);
        self::assertStringContainsString("LOWER(\"t0\".\"title\") LIKE LOWER(?) ESCAPE '\\'", $sql['sql']);
        self::assertStringContainsString('"t2"."name" IN (?)', $sql['sql']);
        self::assertStringContainsString('ORDER BY "t0"."title" DESC, "t0"."id" ASC', $sql['sql']);
        self::assertStringContainsString('LIMIT 5 OFFSET 10', $sql['sql']);
        self::assertSame(['%a\\_b%', 'x'], $sql['bindings']);
    }
}
