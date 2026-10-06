<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Utils\Errors\PaginationError;
use Strapi\Utils\Pagination;

/** Port of __tests__/pagination.test.ts. */
final class PaginationTest extends TestCase
{
    private const DEFAULT_LIMIT = 20;
    private const DEFAULTS = ['offset' => ['limit' => self::DEFAULT_LIMIT], 'page' => ['pageSize' => self::DEFAULT_LIMIT]];

    public function testDoesNotChangeQueryArgumentsOrDefaultsAcrossCalls(): void
    {
        $filters = ['title' => ['$eq' => 'example']];
        $query = ['page' => 2, 'pageSize' => 5, 'filters' => $filters];
        $customDefaults = ['page' => ['pageSize' => 20], 'offset' => ['limit' => 30]];

        $result = Pagination::withDefaultPagination($query, $customDefaults);

        self::assertEquals(['start' => 5, 'limit' => 5, 'filters' => $filters], $result);
        self::assertSame(['page' => 2, 'pageSize' => 5, 'filters' => $filters], $query);
        self::assertSame(['start' => 0, 'limit' => 10], Pagination::withDefaultPagination([]));
    }

    public function testThrowsWhenMixingPageAndOffset(): void
    {
        $this->expectException(PaginationError::class);
        Pagination::withDefaultPagination(['page' => 1, 'limit' => 2]);
    }

    /** @return iterable<string, array{array<string, int|null>, int, int}> */
    public static function withMaxLimit(): iterable
    {
        yield 'default limit' => [[], 0, self::DEFAULT_LIMIT];
        yield 'specified pageSize' => [['pageSize' => 5], 0, 5];
        yield 'default pageSize when only page' => [['page' => 2], self::DEFAULT_LIMIT, self::DEFAULT_LIMIT];
        yield 'default pageSize when pageSize null' => [['page' => 2, 'pageSize' => null], self::DEFAULT_LIMIT, self::DEFAULT_LIMIT];
        yield 'maxLimit as pageSize' => [['pageSize' => 999], 0, 50];
        yield '1 as pageSize (0)' => [['pageSize' => 0], 0, 1];
        yield '1 as pageSize (-1)' => [['pageSize' => -1], 0, 1];
        yield '1 as pageSize (-2)' => [['pageSize' => -2], 0, 1];
        yield 'specified limit' => [['limit' => 5], 0, 5];
        yield 'maxLimit as limit' => [['limit' => 999], 0, 50];
        yield '1 as limit (0)' => [['limit' => 0], 0, 1];
        yield 'maxLimit as limit (-1)' => [['limit' => -1], 0, 50];
        yield '1 as limit (-2)' => [['limit' => -2], 0, 1];
    }

    /**
     * @param array<string, int|null> $pagination
     */
    #[DataProvider('withMaxLimit')]
    public function testWithMaxLimit(array $pagination, int $start, int $limit): void
    {
        self::assertSame(['start' => $start, 'limit' => $limit], Pagination::withDefaultPagination($pagination, self::DEFAULTS, 50));
    }

    public function testUsesTheCappedDefaultPageSizeToCalculateTheOffset(): void
    {
        self::assertSame(['start' => 2, 'limit' => 2], Pagination::withDefaultPagination(['page' => 2], ['page' => ['pageSize' => 3]], 2));
    }

    /** @return iterable<string, array{array<string, int>, int, int}> */
    public static function withoutMaxLimit(): iterable
    {
        yield 'default limit' => [[], 0, self::DEFAULT_LIMIT];
        yield 'specified pageSize' => [['pageSize' => 5], 0, 5];
        yield 'large pageSize' => [['pageSize' => 999], 0, 999];
        yield '1 as pageSize (0)' => [['pageSize' => 0], 0, 1];
        yield '1 as pageSize (-1)' => [['pageSize' => -1], 0, 1];
        yield '1 as pageSize (-2)' => [['pageSize' => -2], 0, 1];
        yield 'specified limit' => [['limit' => 5], 0, 5];
        yield 'large limit' => [['limit' => 999], 0, 999];
        yield '1 as limit (0)' => [['limit' => 0], 0, 1];
        yield '-1 as limit' => [['limit' => -1], 0, -1];
        yield '1 as limit (-2)' => [['limit' => -2], 0, 1];
    }

    /**
     * @param array<string, int> $pagination
     */
    #[DataProvider('withoutMaxLimit')]
    public function testWithoutMaxLimit(array $pagination, int $start, int $limit): void
    {
        self::assertSame(['start' => $start, 'limit' => $limit], Pagination::withDefaultPagination($pagination, self::DEFAULTS));
    }

    public function testPagedPaginationInfo(): void
    {
        self::assertSame(['page' => 2, 'pageSize' => 10, 'pageCount' => 10, 'total' => 100], Pagination::transformPagedPaginationInfo(['page' => 2, 'pageSize' => 10], 100));
        self::assertSame(['page' => 2, 'pageSize' => 100, 'pageCount' => 1, 'total' => 100], Pagination::transformPagedPaginationInfo(['page' => 2], 100));
        self::assertSame(['page' => 2, 'pageSize' => 10, 'pageCount' => 10, 'total' => 100], Pagination::transformPagedPaginationInfo(['start' => 10, 'limit' => 10], 100));
        self::assertSame(['page' => 1, 'pageSize' => 100, 'pageCount' => 1, 'total' => 100], Pagination::transformPagedPaginationInfo(['start' => 10], 100));
        self::assertSame(['page' => 1, 'pageSize' => 10, 'pageCount' => 1, 'total' => 3], Pagination::transformPagedPaginationInfo([], 3));
    }

    public function testOffsetPaginationInfo(): void
    {
        self::assertSame(['start' => 10, 'limit' => 10, 'total' => 100], Pagination::transformOffsetPaginationInfo(['page' => 2, 'pageSize' => 10], 100));
        self::assertSame(['start' => 100, 'limit' => 100, 'total' => 100], Pagination::transformOffsetPaginationInfo(['page' => 2], 100));
        self::assertSame(['start' => 10, 'limit' => 10, 'total' => 100], Pagination::transformOffsetPaginationInfo(['start' => 10, 'limit' => 10], 100));
        self::assertSame(['start' => 10, 'limit' => 100, 'total' => 100], Pagination::transformOffsetPaginationInfo(['start' => 10], 100));
        self::assertSame(['start' => 0, 'limit' => 10, 'total' => 3], Pagination::transformOffsetPaginationInfo([], 3));
    }
}
