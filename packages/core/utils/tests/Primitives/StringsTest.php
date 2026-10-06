<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests\Primitives;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Utils\Primitives\Strings;

/** Port of primitives/__tests__/strings.test.ts. */
final class StringsTest extends TestCase
{
    /** @return iterable<array{mixed, mixed, bool}> */
    public static function isEqualCases(): iterable
    {
        yield ['1', '1', true];
        yield ['1', '4', false];
        yield [1, 1, true];
        yield [1, 4, false];
        yield [1, '1', true];
        yield [1, '4', false];
        yield [1, '01', false];
        yield ['1', 1, true];
        yield ['1', 4, false];
        yield ['01', 1, false];
    }

    #[DataProvider('isEqualCases')]
    public function testIsEqual(mixed $a, mixed $b, bool $expected): void
    {
        self::assertSame($expected, Strings::isEqual($a, $b));
    }

    /** @return iterable<array{list<string>, string}> */
    public static function commonPaths(): iterable
    {
        yield [['abc', 'ab'], ''];
        yield [['http://ab.com/cd', 'http://ab.com/c'], 'http://ab.com'];
        yield [['http://ab.com/admin', 'http://ab.com/api'], 'http://ab.com'];
        yield [['http://ab.com/admin', 'http://ab.com/admin/'], 'http://ab.com/admin'];
        yield [['http://ab.com/admin', 'http://ab.com/admin'], 'http://ab.com/admin'];
    }

    /** @param list<string> $paths */
    #[DataProvider('commonPaths')]
    public function testGetCommonPath(array $paths, string $expected): void
    {
        self::assertSame($expected, Strings::getCommonPath(...$paths));
    }

    /** @return iterable<array{string, string}> */
    public static function regressedEnumValues(): iterable
    {
        yield ['', ''];
        yield ['a', 'a'];
        yield ['aa', 'aa'];
        yield ['aBa', 'aBa'];
        yield ['ABa', 'ABa'];
        yield ['ABA', 'ABA'];
        yield ['a a', 'a_a'];
        yield ['aa aa', 'aa_aa'];
        yield ['aBa aBa', 'aBa_aBa'];
        yield ['ABa ABa', 'ABa_ABa'];
        yield ['ABA ABA', 'ABA_ABA'];
        yield ['û', 'u'];
        yield ['Û', 'U'];
        yield ['München', 'Muenchen'];
        yield ['Baden-Württemberg', 'Baden_Wuerttemberg'];
        yield ['test_test', 'test_test'];
    }

    #[DataProvider('regressedEnumValues')]
    public function testToRegressedEnumValue(string $input, string $expected): void
    {
        self::assertSame($expected, Strings::toRegressedEnumValue($input));
    }

    /** @return iterable<array{list<string>, string}> */
    public static function joinByCases(): iterable
    {
        yield [['/', ''], ''];
        yield [['/', '/a/'], '/a/'];
        yield [['/', 'a', 'b'], 'a/b'];
        yield [['/', 'a', '/b'], 'a/b'];
        yield [['/', 'a/', '/b'], 'a/b'];
        yield [['/', 'a/', 'b'], 'a/b'];
        yield [['/', 'a//', 'b'], 'a/b'];
        yield [['/', 'a//', '//b'], 'a/b'];
        yield [['/', 'a', '//b'], 'a/b'];
        yield [['/', '/a//', '//b/'], '/a/b/'];
        yield [['/', 'a', 'b', 'c'], 'a/b/c'];
        yield [['/', 'a/', '/b/', '/c'], 'a/b/c'];
        yield [['/', 'a//', '//b//', '//c'], 'a/b/c'];
        yield [['/', '///a///', '///b///', '///c///'], '///a/b/c///'];
    }

    /** @param list<string> $args */
    #[DataProvider('joinByCases')]
    public function testJoinBy(array $args, string $expected): void
    {
        $joint = array_shift($args);
        self::assertSame($expected, Strings::joinBy($joint, ...$args));
    }

    public function testSlugsAndCases(): void
    {
        self::assertSame('hello-world', Strings::nameToSlug('Hello World!'));
        self::assertSame('hello_world', Strings::nameToCollectionName('Hello World'));
        self::assertSame('foo-bar-baz', Strings::toKebabCase('fooBar baz'));
        self::assertSame('foo-bar', Strings::kebabCase('FooBar'));
        self::assertSame('created_by_id', Strings::snakeCase('createdById'));
        self::assertSame('fooBarBaz', Strings::camelCase('foo-bar_baz'));
        self::assertTrue(Strings::isCamelCase('fooBar'));
        self::assertFalse(Strings::isCamelCase('FooBar'));
        self::assertTrue(Strings::isKebabCase('foo-bar'));
        self::assertFalse(Strings::isKebabCase('foo_bar'));
        self::assertTrue(Strings::startsWithANumber('1abc'));
        self::assertFalse(Strings::startsWithANumber('abc'));
    }
}
