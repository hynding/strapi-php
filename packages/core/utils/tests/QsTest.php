<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Utils\Qs;

/**
 * Tests for the `qs` port. Expected values were produced by running the npm `qs` 6.x library with
 * Strapi's options (`{ strictNullHandling: true, arrayLimit: 100, depth: 20 }`).
 */
final class QsTest extends TestCase
{
    /** @return iterable<string, array{string, array<string|int, mixed>}> */
    public static function strapiParse(): iterable
    {
        yield 'simple' => ['a=1', ['a' => '1']];
        yield 'dots in keys are not split' => ['a.b=1', ['a.b' => '1']];
        yield 'spaces in keys and values' => ['a b=1&c=d e', ['a b' => '1', 'c' => 'd e']];
        yield 'plus and percent decoding' => ['x=%E2%9C%93&y=caf%C3%A9+au+lait', ['x' => '✓', 'y' => 'café au lait']];
        yield 'brackets' => ['a[b][c]=1', ['a' => ['b' => ['c' => '1']]]];
        yield 'encoded brackets' => ['a%5Bb%5D=1', ['a' => ['b' => '1']]];
        yield 'indices' => ['a[0]=x&a[1]=y', ['a' => ['x', 'y']]];
        yield 'out of order indices' => ['a[1]=x&a[0]=y', ['a' => ['y', 'x']]];
        yield 'sparse index compacts' => ['a[2]=x', ['a' => ['x']]];
        yield 'empty brackets' => ['a[]=x&a[]=y', ['a' => ['x', 'y']]];
        yield 'repeated key' => ['a=1&a=2', ['a' => ['1', '2']]];
        yield 'three repeated keys' => ['a=1&a=2&a=3', ['a' => ['1', '2', '3']]];
        yield 'mixed repeat and brackets' => ['a[]=1&a[]=2&a=3', ['a' => ['1', '2', '3']]];
        yield 'mixed repeat then brackets' => ['a=1&a=2&a[]=3', ['a' => ['1', '2', '3']]];
        yield 'strapi filters' => ['filters[title][$eq]=hello&filters[$or][0][a]=1&filters[$or][1][b]=2', ['filters' => ['title' => ['$eq' => 'hello'], '$or' => [['a' => '1'], ['b' => '2']]]]];
        yield 'wildcard populate' => ['populate=*', ['populate' => '*']];
        yield 'populate list' => ['populate[0]=a&populate[1]=b', ['populate' => ['a', 'b']]];
        yield 'nested fields' => ['fields[0]=title&fields[1]=desc&populate[author][fields][0]=name', ['fields' => ['title', 'desc'], 'populate' => ['author' => ['fields' => ['name']]]]];
        yield 'sort[] with strictNullHandling' => ['sort[]', ['sort' => [null]]];
        yield 'sort[]=value' => ['sort[]=title:asc', ['sort' => ['title:asc']]];
        yield 'key without equals' => ['a', ['a' => null]];
        yield 'empty value' => ['a=', ['a' => '']];
        yield 'empty values in array' => ['a[]=&a[]=', ['a' => ['', '']]];
        yield 'object keys' => ['a[b]=1&a[c]=2', ['a' => ['b' => '1', 'c' => '2']]];
        yield 'object then scalar' => ['a[b]=1&a=2', ['a' => [['b' => '1'], '2']]];
        yield 'scalar then object' => ['a=1&a[b]=2', ['a' => ['1', ['b' => '2']]]];
        yield 'duplicate nested key' => ['a[b]=1&a[b]=2', ['a' => ['b' => ['1', '2']]]];
        yield 'nested brackets list' => ['a[b][]=1&a[b][]=2', ['a' => ['b' => ['1', '2']]]];
        yield 'object list items' => ['a[0][b]=1&a[0][c]=2', ['a' => [['b' => '1', 'c' => '2']]]];
        yield 'same index twice' => ['a[0]=1&a[0]=2', ['a' => [['1', '2']]]];
        yield 'index then empty brackets' => ['a[0]=x&a[]=y', ['a' => ['x', 'y']]];
        yield 'scalar then nested' => ['a[b]=1&a[b][c]=2', ['a' => ['b' => ['1', ['c' => '2']]]]];
        yield 'nested then scalar' => ['a[b][c]=2&a[b]=1', ['a' => ['b' => [['c' => '2'], '1']]]];
        yield 'unterminated bracket' => ['a[b=1', ['a' => ['[b' => '1']]];
        yield 'nested unterminated bracket' => ['a[[]b=1', ['a' => ['[[]b' => '1']]];
        yield 'stray close bracket' => ['a]b=1', ['a]b' => '1']];
        yield 'empty segment' => ['a=1&&b=2', ['a' => '1', 'b' => '2']];
        yield 'empty key' => ['=1', []];
        yield 'double equals' => ['a==1', ['a' => '=1']];
        yield 'equals in value' => ['a=1=2', ['a' => '1=2']];
        yield 'question mark is not stripped' => ['?a=1', ['?a' => '1']];
        yield 'empty string' => ['', []];
        yield 'index 99 within limit' => ['a[99]=x', ['a' => ['x']]];
        yield 'index 100 beyond limit is a key' => ['a[100]=x', ['a' => ['100' => 'x']]];
        yield 'mixed index beyond limit' => ['a[0]=x&a[100]=y', ['a' => ['0' => 'x', '100' => 'y']]];
        yield 'negative index is a key' => ['a[-1]=x', ['a' => ['-1' => 'x']]];
        yield 'leading zero is a key' => ['a[01]=x', ['a' => ['01' => 'x']]];
        yield 'float index is a key' => ['a[1.5]=x', ['a' => ['1.5' => 'x']]];
        yield 'index with space is a key' => ['a[ 1]=x', ['a' => [' 1' => 'x']]];
        yield 'object with numeric key order' => ['a[b]=1&a[0]=2', ['a' => [0 => '2', 'b' => '1']]];
        yield 'numeric then string key' => ['a[0]=1&a[b]=2', ['a' => [0 => '1', 'b' => '2']]];
        yield 'object merged with scalars' => ['a[x]=1&a=2&a=3', ['a' => [0 => '2', 1 => '3', 'x' => '1']]];
        yield 'list merged with object' => ['a[]=1&a[x]=2', ['a' => [0 => '1', 'x' => '2']]];
        yield 'depth 20 keeps remainder as literal key' => [
            'a[b][c][d][e][f][g][h][i][j][k][l][m][n][o][p][q][r][s][t][u][v]=1',
            ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => ['i' => ['j' => ['k' => ['l' => ['m' => ['n' => ['o' => ['p' => ['q' => ['r' => ['s' => ['t' => ['u' => ['[v]' => '1']]]]]]]]]]]]]]]]]]]]]],
        ];
    }

    /** @param array<string|int, mixed> $expected */
    #[DataProvider('strapiParse')]
    public function testParseWithStrapiOptions(string $query, array $expected): void
    {
        self::assertSame($expected, Qs::parseStrapiQuery($query));
    }

    /** @return iterable<string, array{string}> */
    public static function prototypeKeys(): iterable
    {
        yield '__proto__ root' => ['__proto__[x]=1'];
        yield '__proto__ nested' => ['a[__proto__]=1'];
        yield 'constructor root' => ['constructor[x]=1'];
        yield 'constructor nested' => ['a[constructor]=1'];
        yield 'toString root' => ['toString=1'];
        yield 'toString nested' => ['a[toString]=1'];
    }

    #[DataProvider('prototypeKeys')]
    public function testPrototypeKeysAreDropped(string $query): void
    {
        self::assertSame([], Qs::parseStrapiQuery($query));
    }

    public function testArrayLimitOverflowBecomesIntKeyedMap(): void
    {
        $query = implode('&', array_map(static fn (int $i): string => "a[{$i}]=v{$i}", range(0, 101)));
        $result = Qs::parseStrapiQuery($query);

        self::assertCount(102, $result['a']);
        self::assertSame('v0', $result['a'][0]);
        self::assertSame('v101', $result['a'][101]);

        $brackets = implode('&', array_map(static fn (int $i): string => "a[]=v{$i}", range(0, 101)));
        self::assertCount(102, Qs::parseStrapiQuery($brackets)['a']);

        $repeat = implode('&', array_map(static fn (int $i): string => "a=v{$i}", range(0, 101)));
        self::assertCount(102, Qs::parseStrapiQuery($repeat)['a']);
    }

    public function testDefaultOptions(): void
    {
        // without strictNullHandling a bare key is an empty string, default depth is 5 and arrayLimit 20
        self::assertSame(['a' => ''], Qs::parse('a'));
        self::assertSame(['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['[g]' => '1']]]]]]], Qs::parse('a[b][c][d][e][f][g]=1'));
        self::assertSame(['a' => ['20' => 'x']], Qs::parse('a[20]=x'));
        self::assertSame(['a' => ['x']], Qs::parse('a[19]=x'));
    }

    public function testParameterLimit(): void
    {
        $query = implode('&', array_map(static fn (int $i): string => "k{$i}={$i}", range(0, 1200)));
        self::assertCount(1000, Qs::parse($query));
        self::assertCount(5, Qs::parse('a=1&b=2&c=3&d=4&e=5&f=6', ['parameterLimit' => 5]));
    }

    public function testParseOptions(): void
    {
        self::assertSame(['a' => ['b' => '1']], Qs::parse('a.b=1', ['allowDots' => true]));
        self::assertSame(['a' => ['1', '2']], Qs::parse('a=1,2', ['comma' => true]));
        self::assertSame(['a' => '1'], Qs::parse('?a=1', ['ignoreQueryPrefix' => true]));
        self::assertSame(['a' => '1'], Qs::parse('a=1&a=2', ['duplicates' => 'first']));
        self::assertSame(['a' => '2'], Qs::parse('a=1&a=2', ['duplicates' => 'last']));
        self::assertSame(['a' => '1', 'b' => '2'], Qs::parse('a=1;b=2', ['delimiter' => ';']));
        self::assertSame(['a[b]' => '1'], Qs::parse('a[b]=1', ['depth' => 0]));
        self::assertSame(['a' => []], Qs::parse('a[]', ['allowEmptyArrays' => true]));
        self::assertSame(['a' => ['b' => '1']], Qs::parse(['a[b]' => '1']));
    }

    public function testStrictDepthThrows(): void
    {
        $this->expectException(\RangeException::class);
        Qs::parse('a[b][c]=1', ['depth' => 1, 'strictDepth' => true]);
    }

    public function testThrowOnLimitExceeded(): void
    {
        $this->expectException(\RangeException::class);
        $this->expectExceptionMessage('Array limit exceeded. Only 2 elements allowed in an array.');
        Qs::parse('a[]=1&a[]=2&a[]=3', ['arrayLimit' => 2, 'throwOnLimitExceeded' => true]);
    }

    /** @return iterable<string, array{array<string|int, mixed>|null, array<string, mixed>, string}> */
    public static function stringifyCases(): iterable
    {
        yield 'scalars nested arrays and objects' => [['a' => 1, 'b' => [1, 2], 'c' => ['d' => 'e f', 'g' => null], 'h' => true, 'i' => 'ü'], [], 'a=1&b%5B0%5D=1&b%5B1%5D=2&c%5Bd%5D=e%20f&c%5Bg%5D=&h=true&i=%C3%BC'];
        yield 'parametrized action' => [['action' => 'read', 'params' => ['x' => 1]], [], 'action=read&params%5Bx%5D=1'];
        yield 'empty array' => [['a' => []], [], ''];
        yield 'deep object' => [['a' => ['b' => ['c' => 'd']]], [], 'a%5Bb%5D%5Bc%5D=d'];
        yield 'objects in lists' => [['a' => [['b' => 1], ['c' => [1, 2]]], 'd' => ['e' => []], 'f' => '', 'g' => false], [], 'a%5B0%5D%5Bb%5D=1&a%5B1%5D%5Bc%5D%5B0%5D=1&a%5B1%5D%5Bc%5D%5B1%5D=2&f=&g=false'];
        yield 'brackets format' => [['a' => [1, 2]], ['arrayFormat' => 'brackets'], 'a%5B%5D=1&a%5B%5D=2'];
        yield 'repeat format' => [['a' => [1, 2]], ['arrayFormat' => 'repeat'], 'a=1&a=2'];
        yield 'comma format' => [['a' => [1, 2]], ['arrayFormat' => 'comma'], 'a=1%2C2'];
        yield 'comma format encodeValuesOnly' => [['a' => [1, 2]], ['arrayFormat' => 'comma', 'encodeValuesOnly' => true], 'a=1,2'];
        yield 'comma format encodeValuesOnly with spaces' => [['a' => ['x y', 'z']], ['arrayFormat' => 'comma', 'encodeValuesOnly' => true], 'a=x%20y,z'];
        yield 'comma format with spaces' => [['a' => ['x y', 'z']], ['arrayFormat' => 'comma'], 'a=x%20y%2Cz'];
        yield 'strict null' => [['a' => null], ['strictNullHandling' => true], 'a'];
        yield 'skip nulls' => [['a' => null, 'b' => 1], ['skipNulls' => true], 'b=1'];
        yield 'dots in keys' => [['a.b' => 1, 'c' => ['d.e' => 2]], [], 'a.b=1&c%5Bd.e%5D=2'];
        yield 'allow empty arrays' => [['a' => []], ['allowEmptyArrays' => true], 'a[]'];
        yield 'allow dots' => [['a' => ['b' => 1]], ['allowDots' => true], 'a.b=1'];
        yield 'RFC1738' => [['a b' => 'c d'], ['format' => 'RFC1738'], 'a+b=c+d'];
        yield 'array filter' => [['a' => ['b' => 1, 'c' => 2]], ['filter' => ['a', 'b']], 'a%5Bb%5D=1'];
        yield 'nulls in lists' => [['a' => [null, 1]], [], 'a%5B0%5D=&a%5B1%5D=1'];
        yield 'nulls in lists strict' => [['a' => [null, 1]], ['strictNullHandling' => true], 'a%5B0%5D&a%5B1%5D=1'];
        yield 'nulls in lists skipped' => [['a' => [null, 1]], ['skipNulls' => true], 'a%5B1%5D=1'];
        yield 'query prefix' => [['a' => 1], ['addQueryPrefix' => true], '?a=1'];
        yield 'no encode' => [['a' => ['b' => 'c']], ['encode' => false], 'a[b]=c'];
        yield 'encode values only' => [['a' => 'b c'], ['encodeValuesOnly' => true], 'a=b%20c'];
        yield 'encode values only with bracket key' => [['a[b]' => 'c d'], ['encodeValuesOnly' => true], 'a[b]=c%20d'];
        yield 'null input' => [null, [], ''];
    }

    /**
     * @param array<string|int, mixed>|null $input
     * @param array<string, mixed> $options
     */
    #[DataProvider('stringifyCases')]
    public function testStringify(?array $input, array $options, string $expected): void
    {
        self::assertSame($expected, Qs::stringify($input, $options));
    }

    public function testStringifyDatesSortAndFilterFunction(): void
    {
        self::assertSame('a=2020-01-02T03%3A04%3A05.123Z', Qs::stringify(['a' => new \DateTimeImmutable('2020-01-02T03:04:05.123Z')]));
        self::assertSame('a=2&b=1', Qs::stringify(['b' => 1, 'a' => 2], ['sort' => static fn (string $x, string $y): int => strcmp($x, $y)]));
        self::assertSame('a=1', Qs::stringify(['a' => 1, 'b' => 2], ['filter' => static fn (string $prefix, mixed $value): mixed => $prefix === 'b' ? null : $value]));
        self::assertSame('a=1', Qs::stringify((object) ['a' => 1]));
    }

    public function testRoundTrip(): void
    {
        $query = ['filters' => ['title' => ['$eq' => 'hello world'], '$or' => [['a' => '1'], ['b' => '2']]], 'populate' => ['author' => ['fields' => ['name']]], 'sort' => ['title:asc']];

        self::assertSame($query, Qs::parseStrapiQuery(Qs::stringify($query)));
    }
}
