<?php

declare(strict_types=1);

namespace Strapi\Permissions\Tests\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Permissions\Engine\Abilities\Sift;

final class SiftTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, mixed, bool}> */
    public static function cases(): iterable
    {
        $doc = ['id' => 5, 'title' => 'Hello', 'tags' => ['a', 'b'], 'author' => ['id' => 2, 'name' => 'Jo'], 'items' => [['n' => 1], ['n' => 3]], 'meta' => ['flags' => ['$custom' => 1]], 'nil' => null];

        yield 'implicit eq' => [['id' => 5], $doc, true];
        yield 'implicit eq mismatch' => [['id' => 6], $doc, false];
        yield 'implicit eq on array contains' => [['tags' => 'a'], $doc, true];
        yield 'implicit eq on array miss' => [['tags' => 'z'], $doc, false];
        yield 'implicit deep eq on array' => [['tags' => ['a', 'b']], $doc, true];
        yield 'object literal deep equality' => [['author' => ['id' => 2, 'name' => 'Jo']], $doc, true];
        yield 'object literal partial is not equal' => [['author' => ['id' => 2]], $doc, false];
        yield 'dot path' => [['author.name' => 'Jo'], $doc, true];
        yield 'dot path through list' => [['items.n' => 3], $doc, true];
        yield 'dot path through list miss' => [['items.n' => 4], $doc, false];
        yield 'dot path index' => [['items.0.n' => 1], $doc, true];
        yield '$eq' => [['id' => ['$eq' => 5]], $doc, true];
        yield '$ne' => [['id' => ['$ne' => 5]], $doc, false];
        yield '$in' => [['id' => ['$in' => [1, 5]]], $doc, true];
        yield '$nin' => [['id' => ['$nin' => [1, 5]]], $doc, false];
        yield '$in on array field' => [['tags' => ['$in' => ['b']]], $doc, true];
        yield '$gt' => [['id' => ['$gt' => 4]], $doc, true];
        yield '$gte' => [['id' => ['$gte' => 5]], $doc, true];
        yield '$lt' => [['id' => ['$lt' => 5]], $doc, false];
        yield '$lte' => [['id' => ['$lte' => 5]], $doc, true];
        yield '$gt on list' => [['items.n' => ['$gt' => 2]], $doc, true];
        yield '$exists true' => [['title' => ['$exists' => true]], $doc, true];
        yield '$exists false on missing' => [['missing' => ['$exists' => false]], $doc, true];
        yield '$exists true on missing' => [['missing' => ['$exists' => true]], $doc, false];
        yield '$exists is key presence, null counts' => [['nil' => ['$exists' => true]], $doc, true];
        yield '$elemMatch' => [['items' => ['$elemMatch' => ['n' => ['$gte' => 3]]]], $doc, true];
        yield '$elemMatch miss' => [['items' => ['$elemMatch' => ['n' => ['$gte' => 4]]]], $doc, false];
        yield '$and' => [['$and' => [['id' => 5], ['title' => 'Hello']]], $doc, true];
        yield '$and miss' => [['$and' => [['id' => 5], ['title' => 'x']]], $doc, false];
        yield '$or' => [['$or' => [['id' => 1], ['title' => 'Hello']]], $doc, true];
        yield '$or miss' => [['$or' => [['id' => 1], ['title' => 'x']]], $doc, false];
        yield '$not' => [['id' => ['$not' => ['$gt' => 10]]], $doc, true];
        yield 'nested $and $or' => [['$and' => [['$or' => [['id' => ['$eq' => 1]], ['id' => ['$ne' => 2]]]], ['id' => ['$in' => [5]]]]], $doc, true];
        yield 'dollar keys as data' => [['meta' => ['flags' => ['$custom' => 1]]], $doc, true];
        yield 'dollar keys as data mismatch' => [['meta' => ['flags' => ['$custom' => 2]]], $doc, false];
        yield 'empty query matches' => [[], $doc, true];
        yield 'object entity' => [['id' => 5], (object) ['id' => 5], true];
        yield 'numeric strings compare numerically' => [['id' => ['$gt' => '4']], $doc, true];
        yield 'string compare' => [['title' => ['$lt' => 'Z']], $doc, true];
        yield 'dates' => [['at' => ['$gte' => new \DateTimeImmutable('2020-01-01')]], ['at' => new \DateTimeImmutable('2021-01-01')], true];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('cases')]
    public function testMatches(array $query, mixed $value, bool $expected): void
    {
        self::assertSame($expected, Sift::createQueryTester($query, Sift::SUPPORTED_OPERATIONS)($value));
    }

    public function testRejectsUnsupportedOperators(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported operation: $startsWith');
        Sift::createQueryTester(['title' => ['$startsWith' => 'x']]);
    }

    public function testRejectsOperatorsOutsideTheGivenWhitelist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported operation: $not');
        Sift::createQueryTester(['id' => ['$not' => ['$eq' => 1]]], Sift::ALLOWED_OPERATIONS);
    }

    public function testValueOperandsAreNotInspected(): void
    {
        $tester = Sift::createQueryTester(['metadata' => ['$eq' => ['$custom' => true]]]);
        self::assertTrue($tester(['metadata' => ['$custom' => true]]));
    }
}
