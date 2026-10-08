<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Mcp;

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Mcp\Sanitizers\ShapeRelations;

/** Port of the unit cases of server/src/mcp/sanitizers/__tests__/shape-relations.test.ts (reduceToIdentity). */
final class ShapeRelationsTest extends TestCase
{
    public function testManyRelationTypes(): void
    {
        foreach (['oneToMany', 'manyToMany', 'manyWay', 'morphToMany', 'morphMany'] as $relation) {
            self::assertTrue(ShapeRelations::isManyRelationForMcp(['relation' => $relation]), $relation);
        }
        foreach (['oneToOne', 'manyToOne', 'oneWay', 'morphToOne', 'morphOne'] as $relation) {
            self::assertFalse(ShapeRelations::isManyRelationForMcp(['relation' => $relation]), $relation);
        }
    }

    public function testReduceToIdentity(): void
    {
        $many = ['relation' => 'manyToMany'];
        $one = ['relation' => 'manyToOne'];

        self::assertSame(
            [['documentId' => 'a', 'locale' => 'en'], ['documentId' => 'b', '__type' => 'api::x.x', 'status' => 'draft']],
            ShapeRelations::reduceToIdentity($many, [
                ['id' => 1, 'documentId' => 'a', 'locale' => 'en', 'title' => 'secret'],
                ['documentId' => 'b', '__type' => 'api::x.x', 'status' => 'draft', 'publishedAt' => null],
                ['id' => 3],
            ]),
        );
        self::assertSame([], ShapeRelations::reduceToIdentity($many, ['count' => 3]));
        self::assertSame([], ShapeRelations::reduceToIdentity($many, null));
        self::assertSame(['documentId' => 'a'], ShapeRelations::reduceToIdentity($one, ['id' => 1, 'documentId' => 'a', 'name' => 'x']));
        self::assertSame(['documentId' => 'a'], ShapeRelations::reduceToIdentity($one, [['documentId' => 'a']]));
        self::assertNull(ShapeRelations::reduceToIdentity($one, null));
        self::assertNull(ShapeRelations::reduceToIdentity($one, ['id' => 1]));
    }
}
