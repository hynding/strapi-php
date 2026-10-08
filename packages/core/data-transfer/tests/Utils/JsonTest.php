<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\DataTransfer\Utils\Json;

/** Port of src/utils/__tests__/json.vitest.test.ts, plus the JSON.stringify emulation the archive relies on. */
final class JsonTest extends TestCase
{
    public function testReturnsEmptyDiffForEqualPrimitives(): void
    {
        self::assertSame([], Json::diff('hello', 'hello'));
        self::assertSame([], Json::diff(1, 1));
        self::assertSame([], Json::diff(null, null));
    }

    public function testDetectsModifiedPrimitives(): void
    {
        self::assertEquals([
            ['kind' => 'modified', 'path' => [], 'types' => ['string', 'string'], 'values' => ['a', 'b']],
        ], Json::diff('a', 'b'));
    }

    public function testDetectsAddedAndDeletedObjectKeys(): void
    {
        self::assertEquals([
            ['kind' => 'added', 'path' => ['b'], 'type' => 'number', 'value' => 2],
        ], Json::diff(['a' => 1], ['a' => 1, 'b' => 2]));

        self::assertEquals([
            ['kind' => 'deleted', 'path' => ['b'], 'type' => 'number', 'value' => 2],
        ], Json::diff(['a' => 1, 'b' => 2], ['a' => 1]));
    }

    public function testDetectsNestedObjectChanges(): void
    {
        self::assertEquals([
            ['kind' => 'modified', 'path' => ['nested', 'count'], 'types' => ['number', 'number'], 'values' => [1, 2]],
        ], Json::diff(['nested' => ['count' => 1]], ['nested' => ['count' => 2]]));
    }

    public function testComparesArraysByIndex(): void
    {
        self::assertEquals([
            ['kind' => 'modified', 'path' => ['1'], 'types' => ['number', 'number'], 'values' => [2, 3]],
        ], Json::diff([1, 2], [1, 3]));
    }

    public function testStringifyMatchesJsonStringify(): void
    {
        // closures are dropped from objects and become null in arrays; floats print like JS numbers
        self::assertSame(
            '{"a":1,"b":48.83,"c":"x/é","list":[null,1],"d":"2024-01-02T03:04:05.000Z"}',
            Json::stringify([
                'a' => 1.0,
                'b' => 48.83,
                'c' => 'x/é',
                'fn' => static fn () => null,
                'list' => [static fn () => null, 1],
                'd' => new \DateTimeImmutable('2024-01-02T03:04:05Z'),
            ])
        );

        self::assertSame("{\n  \"a\": {\n    \"b\": [\n      1\n    ]\n  }\n}", Json::stringify(['a' => ['b' => [1]]], true));
    }
}
