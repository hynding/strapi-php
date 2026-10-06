<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests\Primitives;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Utils\Primitives\Objects;

/** Port of primitives/__tests__/objects.vitest.test.ts. */
final class ObjectsTest extends TestCase
{
    public function testSetCopiesOnlyTheUpdatedPath(): void
    {
        $original = ['properties' => ['fields' => ['title'], 'unchanged' => ['enabled' => true]], 'other' => ['enabled' => true]];
        $fields = ['title', 'slug'];

        $result = Objects::set($original, 'properties.fields', $fields);

        self::assertSame($fields, $result['properties']['fields']);
        self::assertSame(['enabled' => true], $result['properties']['unchanged']);
        self::assertSame(['title'], $original['properties']['fields']);
    }

    public function testSetCopiesArrayContainersWithoutChangingOtherItems(): void
    {
        $original = ['blocks' => [['name' => 'first'], ['name' => 'second']]];

        $result = Objects::set($original, ['blocks', 0, 'name'], 'updated');

        self::assertSame('updated', $result['blocks'][0]['name']);
        self::assertSame(['name' => 'second'], $result['blocks'][1]);
        self::assertSame('first', $original['blocks'][0]['name']);
    }

    public function testSetCreatesMissingContainers(): void
    {
        self::assertSame(['blocks' => [['title' => 'created']]], Objects::set([], 'blocks[0].title', 'created'));
    }

    public function testSetSupportsLiteralDottedKeysWithArrayPaths(): void
    {
        $original = ['literal.key' => ['value' => 1]];
        self::assertSame(['literal.key' => ['value' => 2]], Objects::set($original, ['literal.key', 'value'], 2));
        self::assertSame(1, $original['literal.key']['value']);
    }

    /** @return iterable<array{string}> */
    public static function unsafePaths(): iterable
    {
        yield ['__proto__.polluted'];
        yield ['constructor.prototype.polluted'];
        yield ['nested.__proto__.polluted'];
    }

    #[DataProvider('unsafePaths')]
    public function testDoesNotWriteThroughUnsafePath(string $path): void
    {
        self::assertSame(['nested' => []], Objects::set(['nested' => []], $path, true));
    }

    public function testUpdatesAnExistingLiteralDottedProperty(): void
    {
        $original = ['list.mainField' => 'old', 'list' => ['mainField' => 'nested']];

        self::assertSame(['list.mainField' => 'new', 'list' => ['mainField' => 'nested']], Objects::set($original, 'list.mainField', 'new'));
    }

    public function testKeysDeep(): void
    {
        self::assertSame(['a.b', 'a.c.d', 'e'], Objects::keysDeep(['a' => ['b' => 1, 'c' => ['d' => 2]], 'e' => 3]));
    }

    public function testToPathGetHas(): void
    {
        self::assertSame(['a', 'b', '0', 'c'], Objects::toPath('a.b[0].c'));
        self::assertSame(['a', 'b c'], Objects::toPath('a["b c"]'));
        self::assertSame(2, Objects::get(['a' => ['b' => [1, 2]]], 'a.b[1]'));
        self::assertSame('x', Objects::get(['a' => 1], 'a.b', 'x'));
        self::assertTrue(Objects::has(['a' => ['b' => null]], 'a.b'));
        self::assertFalse(Objects::has(['a' => []], 'a.b'));
    }

    public function testHelpers(): void
    {
        self::assertTrue(Objects::isPlainObject([]));
        self::assertTrue(Objects::isPlainObject(['a' => 1]));
        self::assertFalse(Objects::isPlainObject([1, 2]));
        self::assertTrue(Objects::isEmpty(''));
        self::assertFalse(Objects::isEmpty([0]));
        self::assertSame(['a' => 1], Objects::pick(['a' => 1, 'b' => 2], ['a', 'c']));
        self::assertSame(['b' => 2], Objects::omit(['a' => 1, 'b' => 2], ['a']));
        self::assertSame(['a' => ['b' => 1, 'c' => 2], 'd' => 3], Objects::merge(['a' => ['b' => 1]], ['a' => ['c' => 2], 'd' => 3]));
    }
}
