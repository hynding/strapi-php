<?php

declare(strict_types=1);

namespace Strapi\Database\Tests\EntityManager;

use PHPUnit\Framework\TestCase;
use Strapi\Database\EntityManager\RelationsOrderer;
use Strapi\Database\Errors\InvalidRelationError;

/** Port of entity-manager/__tests__/relations-orderer.test.ts and sort-connect-array.test.ts. */
final class RelationsOrdererTest extends TestCase
{
    /** @param list<array<string, mixed>> $expected */
    private static function assertMatchesObjects(array $expected, array $actual): void
    {
        self::assertCount(count($expected), $actual);
        foreach ($expected as $i => $exp) {
            foreach ($exp as $k => $v) {
                self::assertEquals($v, $actual[$i][$k], "item {$i} key {$k}");
            }
        }
    }

    public function testConnectAtTheEnd(): void
    {
        $orderer = RelationsOrderer::create([['id' => 2, 'order' => 4], ['id' => 3, 'order' => 10]], 'id', 'order');
        $orderer->connect([['id' => 4, 'position' => ['end' => true]], ['id' => 5]]);

        self::assertMatchesObjects([
            ['id' => 2, 'order' => 4],
            ['id' => 3, 'order' => 10],
            ['id' => 4, 'order' => 10.5],
            ['id' => 5, 'order' => 10.5],
        ], $orderer->get());
    }

    public function testConnectAtTheStart(): void
    {
        $orderer = RelationsOrderer::create([['id' => 2, 'order' => 4], ['id' => 3, 'order' => 10]], 'id', 'order');
        $orderer->connect([['id' => 4, 'position' => ['start' => true]]]);

        self::assertMatchesObjects([['id' => 4, 'order' => 3.5], ['id' => 2, 'order' => 4], ['id' => 3, 'order' => 10]], $orderer->get());
    }

    public function testConnectMultipleUsingBefore(): void
    {
        $orderer = RelationsOrderer::create([['id' => 2, 'order' => 4], ['id' => 3, 'order' => 10]], 'id', 'order');
        $orderer->connect([['id' => 4, 'position' => ['before' => 3]], ['id' => 5, 'position' => ['before' => 4]]]);

        self::assertMatchesObjects([
            ['id' => 2, 'order' => 4],
            ['id' => 5, 'order' => 9.5],
            ['id' => 4, 'order' => 9.5],
            ['id' => 3, 'order' => 10],
        ], $orderer->get());
    }

    public function testConnectMultipleDisordered(): void
    {
        $orderer = RelationsOrderer::create([['id' => 1, 'order' => 1], ['id' => 2, 'order' => 2], ['id' => 3, 'order' => 3]], 'id', 'order');
        $orderer->connect([
            ['id' => 5, 'position' => ['before' => 1]],
            ['id' => 1, 'position' => ['before' => 2]],
            ['id' => 2, 'position' => ['end' => true]],
        ]);

        self::assertMatchesObjects([
            ['id' => 5, 'order' => 0.5],
            ['id' => 1, 'order' => 1.5],
            ['id' => 3, 'order' => 3],
            ['id' => 2, 'order' => 3.5],
        ], $orderer->get());
    }

    public function testNonStrictBeforeNonExisting(): void
    {
        $orderer = RelationsOrderer::create([['id' => 1, 'order' => 1], ['id' => 2, 'order' => 2], ['id' => 3, 'order' => 3]], 'id', 'order', false);
        $orderer->connect([['id' => 4, 'position' => ['before' => 5]]]);

        self::assertMatchesObjects([
            ['id' => 1, 'order' => 1],
            ['id' => 2, 'order' => 2],
            ['id' => 3, 'order' => 3],
            ['id' => 4, 'order' => 3.5],
        ], $orderer->get());
    }

    public function testNullOrderIsReplacedByOne(): void
    {
        $orderer = RelationsOrderer::create([['id' => 2, 'order' => null], ['id' => 3, 'order' => null]], 'id', 'order');
        $orderer->connect([['id' => 4, 'position' => ['before' => 3]], ['id' => 5]]);

        self::assertMatchesObjects([
            ['id' => 2, 'order' => 1],
            ['id' => 4, 'order' => 0.5],
            ['id' => 3, 'order' => 1],
            ['id' => 5, 'order' => 1.5],
        ], $orderer->get());
    }

    public function testOrderZeroPreserved(): void
    {
        $orderer = RelationsOrderer::create([['id' => 1, 'order' => 0], ['id' => 2, 'order' => 5]], 'id', 'order');
        $orderer->connect([['id' => 3, 'position' => ['start' => true]]]);

        self::assertMatchesObjects([['id' => 3, 'order' => -0.5], ['id' => 1, 'order' => 0], ['id' => 2, 'order' => 5]], $orderer->get());
    }

    public function testFractionalMinimum(): void
    {
        $orderer = RelationsOrderer::create([['id' => 1, 'order' => 0.5], ['id' => 2, 'order' => 2]], 'id', 'order');
        $orderer->connect([['id' => 3, 'position' => ['start' => true]]]);

        self::assertMatchesObjects([['id' => 3, 'order' => 0], ['id' => 1, 'order' => 0.5], ['id' => 2, 'order' => 2]], $orderer->get());
        self::assertLessThan(0.5, $orderer->getOrderMap()['3']);
    }

    public function testBeforeNeighboursNotLoaded(): void
    {
        $orderer = RelationsOrderer::create([['id' => 1, 'order' => 1], ['id' => 9, 'order' => 9], ['id' => 10, 'order' => 10]], 'id', 'order');
        $orderer->connect([['id' => 4, 'position' => ['before' => 9]]]);
        $map = $orderer->getOrderMap();
        self::assertGreaterThan(8, $map['4']);
        self::assertLessThan(9, $map['4']);
    }

    public function testAfterNeighboursNotLoaded(): void
    {
        $orderer = RelationsOrderer::create([['id' => 1, 'order' => 1], ['id' => 5, 'order' => 5], ['id' => 10, 'order' => 10]], 'id', 'order');
        $orderer->connect([['id' => 4, 'position' => ['after' => 5]]]);
        $map = $orderer->getOrderMap();
        self::assertGreaterThan(5, $map['4']);
        self::assertLessThan(6, $map['4']);
    }

    public function testSeveralBeforeSameAnchor(): void
    {
        $orderer = RelationsOrderer::create([['id' => 1, 'order' => 1], ['id' => 5, 'order' => 5], ['id' => 10, 'order' => 10]], 'id', 'order');
        $orderer->connect([['id' => 6, 'position' => ['before' => 5]], ['id' => 7, 'position' => ['before' => 5]]]);
        $map = $orderer->getOrderMap();
        self::assertGreaterThan(4, $map['6']);
        self::assertLessThan(5, $map['7']);
        self::assertLessThan($map['7'], $map['6']);
    }

    public function testAfterSharedOrder(): void
    {
        $orderer = RelationsOrderer::create([['id' => 2, 'order' => null], ['id' => 3, 'order' => null]], 'id', 'order');
        $orderer->connect([['id' => 4, 'position' => ['after' => 2]]]);

        self::assertMatchesObjects([['id' => 2, 'order' => 1], ['id' => 4, 'order' => 1.5], ['id' => 3, 'order' => 1]], $orderer->get());
    }

    public function testNoRelationsMultipleNew(): void
    {
        $orderer = RelationsOrderer::create([], 'id', 'order');
        $orderer->connect([
            ['id' => 1, 'position' => ['start' => true]],
            ['id' => 2, 'position' => ['start' => true]],
            ['id' => 3, 'position' => ['after' => 1]],
        ]);

        self::assertMatchesObjects([['id' => 2, 'order' => 0.5], ['id' => 1, 'order' => 0.5], ['id' => 3, 'order' => 0.5]], $orderer->get());
    }

    public function testNoRelationsDisordered(): void
    {
        $orderer = RelationsOrderer::create([], 'id', 'order');
        $orderer->connect([
            ['id' => 5, 'position' => ['before' => 1]],
            ['id' => 1, 'position' => ['before' => 2]],
            ['id' => 2, 'position' => ['end' => true]],
            ['id' => 3, 'position' => ['after' => 1]],
        ]);

        self::assertMatchesObjects([
            ['id' => 5, 'order' => 0.5],
            ['id' => 1, 'order' => 0.5],
            ['id' => 3, 'order' => 0.5],
            ['id' => 2, 'order' => 0.5],
        ], $orderer->get());
    }

    public function testSortConnectArray(): void
    {
        $sorted = RelationsOrderer::sortConnectArray([
            ['id' => 5, 'position' => ['before' => 1]],
            ['id' => 1, 'position' => ['before' => 2]],
            ['id' => 2, 'position' => ['end' => true]],
            ['id' => 3, 'position' => ['after' => 1]],
        ]);

        self::assertMatchesObjects([
            ['id' => 2, 'position' => ['end' => true]],
            ['id' => 1, 'position' => ['before' => 2]],
            ['id' => 5, 'position' => ['before' => 1]],
            ['id' => 3, 'position' => ['after' => 1]],
        ], $sorted);
    }

    public function testSortConnectArrayWithInitial(): void
    {
        $sorted = RelationsOrderer::sortConnectArray([
            ['id' => 5, 'position' => ['before' => 1]],
            ['id' => 1, 'position' => ['before' => 2]],
            ['id' => 2, 'position' => ['end' => true]],
            ['id' => 3, 'position' => ['after' => 1]],
        ], [['id' => 1]]);

        self::assertMatchesObjects([
            ['id' => 5, 'position' => ['before' => 1]],
            ['id' => 2, 'position' => ['end' => true]],
            ['id' => 1, 'position' => ['before' => 2]],
            ['id' => 3, 'position' => ['after' => 1]],
        ], $sorted);
    }

    public function testErrorIfPositionDoesNotExist(): void
    {
        $this->expectException(InvalidRelationError::class);
        $this->expectExceptionMessage('There was a problem connecting relation with id 1 at position {"after":2}. The relation with id 2 needs to be connected first.');
        RelationsOrderer::sortConnectArray([['id' => 1, 'position' => ['after' => 2]]]);
    }

    public function testErrorCircular(): void
    {
        $this->expectExceptionMessage('A circular reference was found in the connect array.');
        RelationsOrderer::sortConnectArray([
            ['id' => 2, 'position' => ['after' => 1]],
            ['id' => 3, 'position' => ['after' => 1]],
            ['id' => 1, 'position' => ['after' => 3]],
        ], []);
    }

    public function testErrorSameRelationTwice(): void
    {
        $this->expectExceptionMessage('The relation with id 1 is already connected. You cannot connect the same relation twice.');
        RelationsOrderer::sortConnectArray([
            ['id' => 1, 'position' => ['after' => 2]],
            ['id' => 1, 'position' => ['after' => 3]],
        ], []);
    }
}
