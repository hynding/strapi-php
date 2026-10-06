<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Utils\AuthScope;
use Strapi\Utils\Sanitize\Visitors\RemoveRestrictedRelations;
use Strapi\Utils\Traverse\QueryPopulate;
use Strapi\Utils\Traverse\QuerySort;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;

/** Port of __tests__/query-populate.test.ts and traverse-query-removal.test.ts. */
final class QueryPopulateTest extends TestCase
{
    protected function tearDown(): void
    {
        AuthScope::setVerifier(null);
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return ['kind' => 'collectionType', 'attributes' => [
            'title' => ['type' => 'string'],
            'address' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'api::address.address'],
            'some' => ['type' => 'relation', 'relation' => 'ManyToMany', 'target' => 'api::some.some'],
        ]];
    }

    public function testShouldNotModifyWildcard(): void
    {
        $getModel = static fn (string $uid): array => ['uid' => $uid, 'attributes' => ['street' => ['type' => 'string']]];
        self::assertSame('*', QueryPopulate::traverse(static fn () => null, ['schema' => self::schema(), 'getModel' => $getModel], '*'));
    }

    public function testShouldReturnOnlySelectedPopulatableField(): void
    {
        $getModel = static fn (string $uid): array => ['uid' => $uid, 'attributes' => ['street' => ['type' => 'string']]];
        self::assertSame('address', QueryPopulate::traverse(static fn () => null, ['schema' => self::schema(), 'getModel' => $getModel], 'address'));
    }

    public function testShouldNotRecurseIntoComponentAttributeNamedFiltersInNestedPopulateContext(): void
    {
        $models = [
            'api::vdp.vdp' => ['uid' => 'api::vdp.vdp', 'attributes' => ['name' => ['type' => 'string'], 'filters' => ['type' => 'component', 'component' => 'default.filter-compo']]],
            'default.filter-compo' => ['uid' => 'default.filter-compo', 'attributes' => ['label' => ['type' => 'string']]],
        ];
        $schema = ['kind' => 'collectionType', 'attributes' => ['title' => ['type' => 'string'], 'searchResultsPage' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'api::vdp.vdp']]];
        $visited = [];

        QueryPopulate::traverse(static function (VisitorOptions $o) use (&$visited): void {
            $visited[] = $o->key;
        }, ['schema' => $schema, 'getModel' => static fn (string $uid): array => $models[$uid] ?? ['uid' => $uid, 'attributes' => []]], ['searchResultsPage' => ['filters' => ['publishedAt' => ['$null' => true]]]]);

        self::assertContains('searchResultsPage', $visited);
        self::assertContains('filters', $visited);
        self::assertNotContains('publishedAt', $visited);
    }

    public function testShouldWorkWithFiltersAttribute(): void
    {
        $getModel = static fn (string $uid): array => ['uid' => $uid, 'attributes' => ['filters' => ['type' => 'string']]];
        $schema = ['kind' => 'collectionType', 'attributes' => ['title' => ['type' => 'string'], 'address' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'api::address.address']]];
        $assertions = 0;

        QueryPopulate::traverse(static function (VisitorOptions $o) use (&$assertions): void {
            if ($o->key === 'address') {
                self::assertNull($o->parent);
                self::assertNotNull($o->attribute);
                $assertions += 2;
            }
            if ($o->key === 'filters') {
                self::assertSame('address', $o->parent?->key);
                self::assertNotNull($o->parent?->attribute);
                self::assertNotNull($o->attribute);
                $assertions += 3;
            }
        }, ['schema' => $schema, 'getModel' => $getModel], ['address' => ['filters' => ['name' => 'test']]]);

        self::assertSame(5, $assertions);
    }

    /** @return iterable<string, array{mixed}> */
    public static function morphPopulateValues(): iterable
    {
        yield 'boolean populate' => [true];
        yield 'count populate' => [['count' => true]];
    }

    #[DataProvider('morphPopulateValues')]
    public function testAllowsVisitorsToRemoveMorphToOne(mixed $populateValue): void
    {
        $schema = ['kind' => 'collectionType', 'attributes' => ['related' => ['type' => 'relation', 'relation' => 'morphToOne']]];

        $result = QueryPopulate::traverse(static function (VisitorOptions $o, VisitorUtils $u): void {
            if ($o->key === 'related') {
                $u->remove($o->key);
            }
        }, ['schema' => $schema, 'getModel' => static fn (): array => $schema], ['related' => $populateValue]);

        self::assertSame([], $result);
    }

    public function testPreservesAuthorizedPolymorphicFragmentsFromStringArrayPopulate(): void
    {
        $allowed = ['uid' => 'api::allowed.allowed', 'kind' => 'collectionType', 'attributes' => []];
        $denied = ['uid' => 'api::denied.denied', 'kind' => 'collectionType', 'attributes' => []];
        $schema = ['kind' => 'collectionType', 'attributes' => [
            'morphToOne' => ['type' => 'relation', 'relation' => 'morphToOne'],
            'morphToMany' => ['type' => 'relation', 'relation' => 'morphToMany'],
        ]];
        AuthScope::setVerifier(static fn (mixed $auth, string $scope): bool => $scope !== 'api::denied.denied.find');
        AuthScope::setRegisteredContentTypes(static fn (): array => ['api::allowed.allowed', 'api::denied.denied']);

        $result = QueryPopulate::traverse(new RemoveRestrictedRelations(new \stdClass()), ['schema' => $schema, 'getModel' => static fn (string $uid): array => $uid === 'api::allowed.allowed' ? $allowed : $denied], ['morphToOne', 'morphToMany']);

        AuthScope::setRegisteredContentTypes(null);
        self::assertSame(['morphToOne' => ['on' => ['api::allowed.allowed' => true]], 'morphToMany' => ['on' => ['api::allowed.allowed' => true]]], $result);
    }

    public function testQsArrayLimitExceeded(): void
    {
        $populate = array_map(static fn (int $i): string => "field{$i}", range(0, 100));
        self::assertTrue(QueryPopulate::isQsArrayLimitPopulateObject($populate));
        self::assertFalse(QueryPopulate::isQsArrayLimitPopulateObject(array_slice($populate, 0, 100)));

        $this->expectExceptionMessage('Too many populate entries (101)');
        QueryPopulate::traverse(static fn () => null, ['schema' => self::schema(), 'getModel' => static fn (): array => self::schema()], $populate);
    }

    public function testPathsAndObjectsRoundTrip(): void
    {
        $object = QueryPopulate::pathsToObjectPopulate(['a', 'b.c', 'b.d.e']);
        self::assertSame(['a' => true, 'b' => ['populate' => ['c' => true, 'd' => ['populate' => ['e' => true]]]]], $object);
        self::assertSame(['a', 'b.c', 'b.d.e'], QueryPopulate::objectPopulateToPaths($object));
        self::assertNull(QueryPopulate::objectPopulateToPaths(['a' => ['on' => ['x' => true]]]));
    }

    // ---------------------------------------------------------------------------------------------
    // traverse-query-removal
    // ---------------------------------------------------------------------------------------------

    /** @return iterable<string, array{mixed, mixed}> */
    public static function sortRemovals(): iterable
    {
        yield 'single sort' => ['title:asc', null];
        yield 'chained sorts' => ['title:asc,id:desc', 'id:desc'];
        yield 'array of sorts' => [['title:asc', 'id:desc'], ['id:desc']];
    }

    #[DataProvider('sortRemovals')]
    public function testRemovesAForbiddenFieldFromSort(mixed $input, mixed $expected): void
    {
        $result = QuerySort::traverse(static function (VisitorOptions $o, VisitorUtils $u): void {
            if ($o->key === 'title') {
                $u->remove($o->key);
            }
        }, ['schema' => TestFixtures::ARTICLE_MODEL, 'getModel' => TestFixtures::getModelFn()], $input);

        self::assertSame($expected, $result);
    }

    /** @return iterable<string, array{mixed, mixed}> */
    public static function populateRemovals(): iterable
    {
        yield 'single populate' => ['createdBy', null];
        yield 'nested populate' => ['createdBy.email', null];
        yield 'array of populates' => [['createdBy.email', 'updatedBy'], ['updatedBy']];
    }

    #[DataProvider('populateRemovals')]
    public function testRemovesAForbiddenRelationFromPopulate(mixed $input, mixed $expected): void
    {
        $result = QueryPopulate::traverse(static function (VisitorOptions $o, VisitorUtils $u): void {
            if ($o->key === 'createdBy') {
                $u->remove($o->key);
            }
        }, ['schema' => TestFixtures::ARTICLE_MODEL, 'getModel' => TestFixtures::getModelFn()], $input);

        self::assertSame($expected, $result);
    }
}
