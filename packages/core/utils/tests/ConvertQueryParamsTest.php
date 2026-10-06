<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Utils\ConvertQueryParams;
use Strapi\Utils\Errors\PaginationError;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\QueryParamsTransformer;

/** Port of __tests__/convert-query-params.test.ts. */
final class ConvertQueryParamsTest extends TestCase
{
    private const MODELS = [
        'api::dog.dog' => [
            'uid' => 'api::dog.dog',
            'modelType' => 'contentType',
            'kind' => 'collectionType',
            'info' => ['displayName' => 'Dog', 'singularName' => 'dog', 'pluralName' => 'dogs'],
            'options' => ['populateCreatorFields' => true],
            'attributes' => [
                'title' => ['type' => 'string'],
                'one_to_one' => ['type' => 'relation', 'relation' => 'oneToOne', 'target' => 'api::dog.dog'],
                'cpa' => ['type' => 'component', 'component' => 'default.cpa'],
                'cpb' => ['type' => 'component', 'component' => 'default.cpb'],
                'dz' => ['type' => 'dynamiczone', 'components' => ['default.cpa', 'default.cpb']],
                'morph_to_one' => ['type' => 'relation', 'relation' => 'morphToOne'],
                'morph_to_many' => ['type' => 'relation', 'relation' => 'morphToMany'],
                'createdAt' => ['type' => 'timestamp'],
                'updatedAt' => ['type' => 'timestamp'],
            ],
        ],
        'default.cpa' => ['uid' => 'default.cpa', 'modelType' => 'component', 'attributes' => ['field' => ['type' => 'string']]],
        'default.cpb' => ['uid' => 'default.cpb', 'modelType' => 'component', 'attributes' => ['field' => ['type' => 'integer']]],
    ];

    private static function transformer(): QueryParamsTransformer
    {
        return ConvertQueryParams::createTransformer(['getModel' => static fn (string $uid): ?array => self::MODELS[$uid] ?? null]);
    }

    /** @return array<string, mixed> */
    private static function dog(): array
    {
        return self::MODELS['api::dog.dog'];
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function keptFilters(): iterable
    {
        $date = new \DateTimeImmutable();
        yield 'id' => [['id' => 1234]];
        yield 'string' => [['title' => 'Hello World']];
        yield 'Date' => [['createdAt' => $date]];
        yield '$gt Date' => [['createdAt' => ['$gt' => $date]]];
        yield '$gt string' => [['createdAt' => ['$gt' => '2022-03-17T15:06:57.878Z']]];
        yield '$gt number' => [['createdAt' => ['$gt' => 1234]]];
        yield '$and' => [['$and' => [['title' => 'value'], ['createdAt' => ['$gt' => $date]]]]];
        yield '$between Date Date' => [['$between' => [$date, $date]]];
        yield '$between String Date' => [['$between' => ['2022-03-17T15:06:57.878Z', $date]]];
        yield '$between String String' => [['$between' => ['2022-03-17T15:06:57.878Z', '2022-03-17T15:06:57.878Z']]];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('keptFilters')]
    public function testKeepsFilters(array $input): void
    {
        self::assertEquals($input, self::transformer()->convertFiltersQueryParams($input, self::dog()));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function removedFilters(): iterable
    {
        yield 'invalid attribute' => [['invAttribute' => 'test']];
        yield 'invalid operator' => [['$nope' => 'test']];
        yield 'uppercase operator' => [['$GT' => new \DateTimeImmutable()]];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('removedFilters')]
    public function testRemovesFilters(array $input): void
    {
        self::assertSame([], self::transformer()->convertFiltersQueryParams($input, self::dog()));
    }

    public function testFiltersMustBeArrays(): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('The filters parameter must be an object or an array');
        self::transformer()->convertFiltersQueryParams('title=foo', self::dog());
    }

    public function testFiltersRecurseIntoRelationsComponentsAndRemovePasswords(): void
    {
        $t = self::transformer();
        self::assertSame(['one_to_one' => ['title' => 'x']], $t->convertFiltersQueryParams(['one_to_one' => ['title' => 'x', 'nope' => 1]], self::dog()));
        self::assertSame(['cpa' => ['field' => ['$contains' => 'x']]], $t->convertFiltersQueryParams(['cpa' => ['field' => ['$contains' => 'x']]], self::dog()));
        self::assertSame([], $t->convertFiltersQueryParams(['dz' => ['field' => 'x']], self::dog()));
        self::assertSame(['title' => ['$null' => true]], $t->convertFiltersQueryParams(['title' => ['$null' => 'true']], self::dog()));
        self::assertSame(['$or' => [['title' => 'a']]], $t->convertFiltersQueryParams(['$or' => [['title' => 'a'], ['nope' => 'b']]], self::dog()));
    }

    /** @return iterable<string, array{mixed, array<mixed>}> */
    public static function acceptedSorts(): iterable
    {
        yield 'single field string' => ['title:asc', [['title' => 'asc']]];
        yield 'defaults to asc when order is omitted' => ['title', [['title' => 'asc']]];
        yield 'is case insensitive on order' => ['title:DESC', [['title' => 'DESC']]];
        yield 'array of field strings' => [['title:asc', 'createdAt:desc'], [['title' => 'asc'], ['createdAt' => 'desc']]];
        yield 'comma-separated field string' => ['title:asc,createdAt:desc', [['title' => 'asc'], ['createdAt' => 'desc']]];
        yield 'nested sort object' => [['title' => 'asc'], ['title' => 'asc']];
        yield 'deep sort string' => ['author.name:desc', [['author' => ['name' => 'desc']]]];
        yield 'array of sort objects' => [[['title' => 'asc'], ['createdAt' => 'desc']], [['title' => 'asc'], ['createdAt' => 'desc']]];
        yield 'nested sort object with relation' => [['author' => ['name' => 'asc']], ['author' => ['name' => 'asc']]];
    }

    /** @param array<mixed> $expected */
    #[DataProvider('acceptedSorts')]
    public function testAcceptsSort(mixed $input, array $expected): void
    {
        self::assertSame($expected, self::transformer()->convertSortQueryParams($input));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function rejectedSorts(): iterable
    {
        $order = 'Invalid order. order can only be one of asc|desc|ASC|DESC';
        $sort = 'Invalid sort parameter. Expected a string, an array of strings, a sort object or an array of sort objects';
        yield 'invalid order suffix' => ['title:asc$', $order];
        yield 'unsupported order value' => ['title:ascending', $order];
        yield 'unsupported order value on a nested sort object' => [['title' => 'ascending'], $order];
        yield 'invalid nested sort value type' => [['title' => 123], 'Invalid sort type expected object or string got number'];
        yield 'a number' => [1234, $sort];
        yield 'null' => [null, $sort];
        yield 'toString.call' => ['toString.call', 'Invalid sort query'];
        yield 'hasOwnProperty.call' => ['hasOwnProperty.call', 'Invalid sort query'];
        yield 'constructor' => ['constructor', 'Invalid sort query'];
        yield 'prototype' => ['prototype', 'Invalid sort query'];
        yield '__proto__' => ['__proto__', 'Invalid sort query'];
    }

    #[DataProvider('rejectedSorts')]
    public function testRejectsSort(mixed $input, string $message): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage($message);
        self::transformer()->convertSortQueryParams($input);
    }

    public function testConvertStartQueryParams(): void
    {
        $t = self::transformer();
        self::assertSame(0, $t->convertStartQueryParams(0));
        self::assertSame(1, $t->convertStartQueryParams(1));
        self::assertSame(100, $t->convertStartQueryParams(100));
        self::assertSame(0, $t->convertStartQueryParams('0'));
        self::assertSame(5, $t->convertStartQueryParams('5'));
        self::assertSame(10, $t->convertStartQueryParams('  10  '));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidStarts(): iterable
    {
        yield 'negative' => [-1, 'convertStartQueryParams expected a positive integer got -1'];
        yield 'negative string' => ['-1', 'convertStartQueryParams expected a positive integer'];
        yield 'float' => [1.5, 'convertStartQueryParams expected a positive integer got 1.5'];
        yield 'float string' => ['1.5', 'convertStartQueryParams expected a positive integer'];
        yield 'invalid' => ['invalid', 'convertStartQueryParams expected a positive integer'];
        yield 'NaN' => [NAN, 'convertStartQueryParams expected a positive integer'];
        yield 'undefined' => [null, 'convertStartQueryParams expected a positive integer'];
    }

    #[DataProvider('invalidStarts')]
    public function testInvalidStart(mixed $input, string $message): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage($message);
        self::transformer()->convertStartQueryParams($input);
    }

    public function testConvertLimitQueryParams(): void
    {
        $t = self::transformer();
        self::assertSame(1, $t->convertLimitQueryParams(1));
        self::assertSame(10, $t->convertLimitQueryParams(10));
        self::assertSame(100, $t->convertLimitQueryParams(100));
        self::assertNull($t->convertLimitQueryParams(-1));
        self::assertNull($t->convertLimitQueryParams('-1'));
        self::assertSame(10, $t->convertLimitQueryParams('10'));
        self::assertSame(25, $t->convertLimitQueryParams('  25  '));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function invalidLimits(): iterable
    {
        yield 'negative' => [-2, 'convertLimitQueryParams expected a positive integer got -2'];
        yield 'negative string' => ['-2', 'convertLimitQueryParams expected a positive integer'];
        yield 'float' => [1.5, 'convertLimitQueryParams expected a positive integer'];
        yield 'invalid' => ['invalid', 'convertLimitQueryParams expected a positive integer'];
        yield 'NaN' => [NAN, 'convertLimitQueryParams expected a positive integer'];
    }

    #[DataProvider('invalidLimits')]
    public function testInvalidLimit(mixed $input, string $message): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage($message);
        self::transformer()->convertLimitQueryParams($input);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPopulates(): iterable
    {
        yield 'a number' => [1234];
        yield 'null' => [null];
        yield 'an array with a non-string entry' => [['title', 1234]];
    }

    #[DataProvider('invalidPopulates')]
    public function testRejectsPopulate(mixed $input): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Invalid populate parameter. Expected a string, an array of strings, a populate object');
        self::transformer()->convertPopulateQueryParams($input, self::dog());
    }

    public function testPopulateBasics(): void
    {
        $t = self::transformer();
        self::assertTrue($t->convertPopulateQueryParams('*', self::dog()));
        self::assertSame(['a', 'b'], $t->convertPopulateQueryParams('a, b', self::dog()));
        self::assertSame(['a', 'b', 'c'], $t->convertPopulateQueryParams(['a,b', 'b', 'c'], self::dog()));
        self::assertSame(['one_to_one' => true], $t->convertPopulateQueryParams(['one_to_one' => 'true', 'cpa' => 'false', 'cpb' => false], self::dog()));
        self::assertSame([], $t->convertPopulateQueryParams(['unknown' => ['fields' => ['x']]], self::dog()));
        self::assertSame(['one_to_one' => ['where' => ['title' => 'x'], 'limit' => 2]], $t->convertPopulateQueryParams(['one_to_one' => ['filters' => ['title' => 'x'], 'limit' => 2]], self::dog()));
    }

    public function testShouldNotSelectDocumentIdWhenSelectingFieldsForComponents(): void
    {
        $populate = ['cpa' => ['fields' => ['field']], 'cpb' => ['fields' => ['field']]];

        self::assertSame(
            ['cpa' => ['select' => ['id', 'field']], 'cpb' => ['select' => ['id', 'field']]],
            self::transformer()->convertPopulateQueryParams($populate, self::dog()),
        );
    }

    public function testShouldSelectDocumentIdForNonComponentPopulate(): void
    {
        self::assertSame(
            ['one_to_one' => ['select' => ['id', 'documentId', 'title']]],
            self::transformer()->convertPopulateQueryParams(['one_to_one' => ['fields' => ['title']]], self::dog()),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function morphLikeKeys(): iterable
    {
        yield 'dynamic zone' => ['dz'];
        yield 'morph to one' => ['morph_to_one'];
        yield 'morph to many' => ['morph_to_many'];
    }

    #[DataProvider('morphLikeKeys')]
    public function testInvalidPopulatePropertyForMorphLike(string $key): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage("Invalid nested populate for dog.{$key} (api::dog.dog). Expected a fragment (\"on\") or \"count\" but found {\"filters\":{\"id\":{\"\$in\":[1,2,3]}}}");
        self::transformer()->convertPopulateQueryParams([$key => ['filters' => ['id' => ['$in' => [1, 2, 3]]]]], self::dog());
    }

    /** @return iterable<array{string}> */
    public static function morphKeys(): iterable
    {
        yield ['morph_to_one'];
        yield ['morph_to_many'];
    }

    #[DataProvider('morphKeys')]
    public function testMorphRelationCanDefineAPopulateFragment(string $key): void
    {
        $populate = [$key => ['on' => ['api::dog.dog' => ['fields' => ['title'], 'populate' => 'createdBy']]]];

        self::assertEquals(
            [$key => ['on' => ['api::dog.dog' => ['populate' => ['createdBy'], 'select' => ['id', 'documentId', 'title']]]]],
            self::transformer()->convertPopulateQueryParams($populate, self::dog()),
        );
    }

    public function testDynamicZoneCanDefineAPopulateFragment(): void
    {
        $populate = ['dz' => ['on' => [
            'default.cpa' => ['filters' => ['field' => ['$contains' => 'foo']]],
            'default.cpb' => ['filters' => ['field' => ['$gt' => 0]]],
        ]]];

        self::assertSame(
            ['dz' => ['on' => ['default.cpa' => ['where' => ['field' => ['$contains' => 'foo']]], 'default.cpb' => ['where' => ['field' => ['$gt' => 0]]]]]],
            self::transformer()->convertPopulateQueryParams($populate, self::dog()),
        );
    }

    #[DataProvider('morphLikeKeys')]
    public function testMorphLikeAttributesCanRequestACount(string $key): void
    {
        self::assertSame([$key => ['count' => true]], self::transformer()->convertPopulateQueryParams([$key => ['count' => true]], self::dog()));
    }

    #[DataProvider('morphLikeKeys')]
    public function testMorphLikeAttributesIgnoreNilWildcardPopulate(string $key): void
    {
        self::assertSame([$key => []], self::transformer()->convertPopulateQueryParams([$key => ['populate' => null]], self::dog()));
    }

    #[DataProvider('morphLikeKeys')]
    public function testMorphLikeAttributesAcceptWildcardPopulateOnlyAsStar(string $key): void
    {
        self::assertSame([$key => ['populate' => true]], self::transformer()->convertPopulateQueryParams([$key => ['populate' => '*']], self::dog()));
    }

    #[DataProvider('morphLikeKeys')]
    public function testMorphLikeAttributesRejectNonWildcardStringPopulate(string $key): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage("Invalid nested population query detected. When using 'populate' within polymorphic structures, its value must be '*' to indicate all second level links. Specific field targeting is not supported here. Consider using the fragment API for more granular population control.");
        self::transformer()->convertPopulateQueryParams([$key => ['populate' => 'deep']], self::dog());
    }

    #[DataProvider('morphLikeKeys')]
    public function testMorphLikeAttributesRejectObjectPopulate(string $key): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Invalid nested population query detected.');
        self::transformer()->convertPopulateQueryParams([$key => ['populate' => ['title' => true]]], self::dog());
    }

    public function testFragmentsNotPermittedOnRegularRelations(): void
    {
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Using fragments is not permitted to populate "one_to_one" in "api::dog.dog"');
        self::transformer()->convertPopulateQueryParams(['one_to_one' => ['on' => ['api::dog.dog' => true]]], self::dog());
    }

    public function testArrayPopulateStringsStayArrays(): void
    {
        self::assertSame(['dz', 'dz.field'], self::transformer()->convertPopulateQueryParams(['dz', 'dz.field'], self::dog()));
    }

    public function testConvertFieldsQueryParams(): void
    {
        $t = self::transformer();
        self::assertNull($t->convertFieldsQueryParams('*', self::dog()));
        self::assertSame(['id', 'documentId', 'title', 'createdAt'], $t->convertFieldsQueryParams('title, createdAt', self::dog()));
        self::assertSame(['id', 'documentId', 'title'], $t->convertFieldsQueryParams(['title', 'id'], self::dog()));
        self::assertSame(['id', 'field'], $t->convertFieldsQueryParams(['field'], self::MODELS['default.cpa']));

        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Invalid fields parameter. Expected a string or an array of strings');
        $t->convertFieldsQueryParams(123, self::dog());
    }

    public function testTransformQueryParamsIncludesAllSupportedParams(): void
    {
        $result = self::transformer()->transformQueryParams('api::dog.dog', [
            'filters' => ['title' => 'Hello'],
            'sort' => ['title' => 'asc'],
            'fields' => ['title', 'createdAt'],
            'populate' => ['one_to_one' => true],
            'page' => 1,
            'pageSize' => 10,
            'status' => 'published',
            '_q' => 'search',
            'count' => true,
            'ordering' => ['title' => 'asc'],
            'unknownParam' => 'ignored',
        ]);

        self::assertSame(['title' => 'Hello'], $result['where']);
        self::assertSame(['title' => 'asc'], $result['orderBy']);
        self::assertSame(['id', 'documentId', 'title', 'createdAt'], $result['select']);
        self::assertSame(['one_to_one' => true], $result['populate']);
        self::assertSame(1, $result['page']);
        self::assertSame(10, $result['pageSize']);
        self::assertIsCallable($result['filters']);
        self::assertSame('search', $result['_q']);
        self::assertTrue($result['count']);
        self::assertSame(['title' => 'asc'], $result['ordering']);
        self::assertSame('ignored', $result['unknownParam']);

        // status filter resolves per target model, only when draft & publish is enabled
        self::assertSame([], $result['filters'](['meta' => ['uid' => 'api::dog.dog']]));
    }

    public function testStatusFilterOnDraftAndPublishModel(): void
    {
        $models = self::MODELS;
        $models['api::dog.dog']['options']['draftAndPublish'] = true;
        $t = ConvertQueryParams::createTransformer(['getModel' => static fn (string $uid): ?array => $models[$uid] ?? null]);

        $published = $t->transformQueryParams('api::dog.dog', ['status' => 'published']);
        $draft = $t->transformQueryParams('api::dog.dog', ['status' => 'draft']);

        self::assertSame(['publishedAt' => ['$null' => false]], $published['filters'](['meta' => ['uid' => 'api::dog.dog']]));
        self::assertSame(['publishedAt' => ['$null' => true]], $draft['filters'](['meta' => ['uid' => 'api::dog.dog']]));
        self::assertSame([], $draft['filters'](['meta' => ['uid' => 'default.cpa']]));
    }

    public function testIncludesOffsetAndLimitWhenUsingStartLimitPagination(): void
    {
        $result = self::transformer()->transformQueryParams('api::dog.dog', ['filters' => ['id' => ['$gt' => 0]], 'start' => 5, 'limit' => 20]);

        self::assertSame(['id' => ['$gt' => 0]], $result['where']);
        self::assertSame(5, $result['offset']);
        self::assertSame(20, $result['limit']);
    }

    public function testPageAndPageSizeCoercion(): void
    {
        $result = self::transformer()->transformQueryParams('api::dog.dog', ['page' => '2', 'pageSize' => '20']);
        self::assertSame(2, $result['page']);
        self::assertSame(20, $result['pageSize']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidPagination(): iterable
    {
        yield 'page 0' => [['page' => 0, 'pageSize' => 10], "Invalid 'page' parameter"];
        yield 'page invalid' => [['page' => 'invalid', 'pageSize' => 10], "Invalid 'page' parameter"];
        yield 'page -1' => [['page' => -1, 'pageSize' => 10], "Invalid 'page' parameter"];
        yield 'pageSize 0' => [['page' => 1, 'pageSize' => 0], "Invalid 'pageSize' parameter"];
        yield 'pageSize invalid' => [['page' => 1, 'pageSize' => 'invalid'], "Invalid 'pageSize' parameter"];
        yield 'mixed pagination' => [['page' => 1, 'limit' => 5], 'Invalid pagination attributes'];
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('invalidPagination')]
    public function testThrowsPaginationError(array $params, string $message): void
    {
        $this->expectException(PaginationError::class);
        $this->expectExceptionMessage($message);
        self::transformer()->transformQueryParams('api::dog.dog', $params);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function noSorts(): iterable
    {
        yield 'missing sort' => [[]];
        yield 'null sort' => [['sort' => null]];
        yield 'empty sort array' => [['sort' => []]];
        yield 'empty sort string' => [['sort' => '']];
        yield 'comma-only sort string' => [['sort' => ',']];
        yield 'empty sort object' => [['sort' => []]];
        yield 'array of empty strings' => [['sort' => ['']]];
        yield 'array of empty sort objects' => [['sort' => [[]]]];
        yield 'array with only null (qs sort[])' => [['sort' => [null]]];
        yield 'object sort with empty order' => [['sort' => ['title' => '']]];
    }

    /** @param array<string, mixed> $params */
    #[DataProvider('noSorts')]
    public function testTreatsAsNoSort(array $params): void
    {
        $result = self::transformer()->transformQueryParams('api::dog.dog', [...$params, 'limit' => 10]);

        self::assertArrayNotHasKey('orderBy', $result);
        self::assertSame(10, $result['limit']);
    }

    public function testStillAppliesSortWhenAFieldIsPresent(): void
    {
        $result = self::transformer()->transformQueryParams('api::dog.dog', ['sort' => 'title:asc', 'limit' => 10]);
        self::assertSame([['title' => 'asc']], $result['orderBy']);
    }

    /** @return iterable<string, array{string, list<array<string, string>>}> */
    public static function trailingCommas(): iterable
    {
        yield 'trailing comma' => ['title:asc,', [['title' => 'asc']]];
        yield 'trailing comma and space' => ['title:asc, ', [['title' => 'asc']]];
        yield 'multiple fields with trailing comma' => ['title:asc,createdAt:desc,', [['title' => 'asc'], ['createdAt' => 'desc']]];
    }

    /** @param list<array<string, string>> $expected */
    #[DataProvider('trailingCommas')]
    public function testDropsEmptySegments(string $sort, array $expected): void
    {
        $result = self::transformer()->transformQueryParams('api::dog.dog', ['sort' => $sort, 'limit' => 10]);
        self::assertSame($expected, $result['orderBy']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function emptyNestedSorts(): iterable
    {
        yield 'empty sort array' => [[]];
        yield 'empty sort string' => [''];
        yield 'comma-only sort string' => [','];
    }

    #[DataProvider('emptyNestedSorts')]
    public function testDoesNotSetNestedPopulateOrderBy(mixed $sortValue): void
    {
        $result = self::transformer()->transformQueryParams('api::dog.dog', ['populate' => ['one_to_one' => ['sort' => $sortValue, 'limit' => 5]]]);

        self::assertSame(['one_to_one' => ['limit' => 5]], $result['populate']);
    }

    public function testSetsNestedPopulateOrderByWhenSortHasAField(): void
    {
        $result = self::transformer()->transformQueryParams('api::dog.dog', ['populate' => ['one_to_one' => ['sort' => 'title:asc', 'limit' => 5]]]);

        self::assertSame(['one_to_one' => ['orderBy' => [['title' => 'asc']], 'limit' => 5]], $result['populate']);
    }

    public function testRejectsInheritedBuiltInSortPathInNestedPopulate(): void
    {
        $this->expectException(ValidationError::class);
        self::transformer()->transformQueryParams('api::dog.dog', ['populate' => ['one_to_one' => ['sort' => 'toString.call']]]);
    }

    public function testDropsTrailingCommaSegmentsInNestedPopulateSort(): void
    {
        $result = self::transformer()->transformQueryParams('api::dog.dog', ['populate' => ['one_to_one' => ['sort' => 'title:asc,', 'limit' => 5]]]);

        self::assertSame(['one_to_one' => ['orderBy' => [['title' => 'asc']], 'limit' => 5]], $result['populate']);
    }
}
