<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Utils\SortQuery;

/** Port of __tests__/sort-query.test.ts. */
final class SortQueryTest extends TestCase
{
    /** @return iterable<string, array{mixed}> */
    public static function noSort(): iterable
    {
        yield 'null' => [null];
        yield 'empty array' => [[]];
        yield 'empty string' => [''];
        yield 'comma-only string' => [','];
        yield 'array of empty strings' => [['']];
        yield 'array of empty sort objects' => [[[]]];
        yield 'qs sort[] (null entry)' => [[null]];
        yield 'object sort with empty order' => [['title' => '']];
        yield 'number' => [12];
    }

    #[DataProvider('noSort')]
    public function testReturnsFalse(mixed $sort): void
    {
        self::assertFalse(SortQuery::hasSort($sort));
    }

    /** @return iterable<string, array{mixed}> */
    public static function sorts(): iterable
    {
        yield 'string sort' => ['title:asc'];
        yield 'array with field' => [['title:asc']];
        yield 'object sort' => [['title' => 'asc']];
        yield 'nested object sort' => [['author' => ['name' => 'desc']]];
    }

    #[DataProvider('sorts')]
    public function testReturnsTrue(mixed $sort): void
    {
        self::assertTrue(SortQuery::hasSort($sort));
    }

    public function testGetMeaningfulSortSegments(): void
    {
        self::assertSame(['title:asc', 'id:desc'], SortQuery::getMeaningfulSortSegments(' title:asc , id:desc,, :asc,'));
    }
}
