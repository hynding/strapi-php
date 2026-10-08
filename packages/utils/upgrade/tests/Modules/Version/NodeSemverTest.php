<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\Version;

use PHPUnit\Framework\Attributes\DataProvider;
use Strapi\Upgrade\Modules\Version\NodeSemver\Range;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer;
use Strapi\Upgrade\Tests\TestCase;

/**
 * PHP-only: the port of npm `semver` (checked case by case against semver@7.7.4 while porting;
 * these are the cases the upgrade tool depends on), and VERSIONING.md's fourth number.
 */
final class NodeSemverTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function ranges(): iterable
    {
        yield 'caret' => ['^1.2.3', '>=1.2.3 <2.0.0-0'];
        yield 'caret zero' => ['^0.0.3', '>=0.0.3 <0.0.4-0'];
        yield 'caret pre' => ['^1.2.3-beta.1', '>=1.2.3-beta.1 <2.0.0-0'];
        yield 'tilde' => ['~1.2', '>=1.2.0 <1.3.0-0'];
        yield 'x-range' => ['1.x', '>=1.0.0 <2.0.0-0'];
        yield 'major only' => ['5', '>=5.0.0 <6.0.0-0'];
        yield 'hyphen' => ['1.2 - 2.3', '>=1.2.0 <2.4.0-0'];
        yield 'spaced comparators' => ['> 1.2.3 < 1.2.5', '>1.2.3 <1.2.5'];
        yield 'lte x' => ['<=1.2', '<1.3.0-0'];
        yield 'gt x' => ['>1.2', '>=1.3.0'];
        yield 'or' => ['1.x || >=2.5.0 || 5.0.0 - 7.2.3', '>=1.0.0 <2.0.0-0||>=2.5.0||>=5.0.0 <=7.2.3'];
        yield 'star' => ['*', ''];
        yield 'nothing' => ['<*', '<0.0.0-0'];
        yield 'upgrade major' => ['>1.0.0 <=2', '>1.0.0 <3.0.0-0'];
    }

    #[DataProvider('ranges')]
    public function testRangeParsing(string $range, string $expected): void
    {
        self::assertSame($expected, (new Range($range))->range());
    }

    public function testPrereleasesOnlyMatchComparatorsOnTheSameTuple(): void
    {
        self::assertTrue(Range::satisfies('1.2.3-beta.2', '^1.2.3-beta.1'));
        self::assertFalse(Range::satisfies('1.2.4-beta.1', '^1.2.3-beta.1'));
        self::assertFalse(Range::satisfies('5.57.0-beta.1', '>5.56.0 <6.0.0'));
        self::assertTrue(Range::satisfies('5.56.0', '>5.56.0-beta.1 <6.0.0'));
    }

    public function testInvalidRangesAndVersions(): void
    {
        self::assertNull(Range::validRange('invalid range'));
        self::assertSame('*', Range::validRange(''));
        $this->expectException(\InvalidArgumentException::class);
        new SemVer('1.2');
    }

    public function testMinVersion(): void
    {
        self::assertSame('4.26.1', Range::minVersion('^4.26.1')?->version);
        self::assertSame('1.2.4', Range::minVersion('>1.2.3')?->version);
        self::assertSame('0.0.0', Range::minVersion('*')?->version);
        self::assertNull(Range::minVersion('<0.0.0-0'));
    }

    public function testInc(): void
    {
        self::assertSame('5.56.0', (new SemVer('5.56.0-beta.1'))->inc('minor')->raw);
        self::assertSame('6.0.0', (new SemVer('5.56.0-beta.1'))->inc('major')->raw);
        self::assertSame('1.0.0', (new SemVer('1.0.0-5'))->inc('major')->raw);
        self::assertSame('1.2.4-0', (new SemVer('1.2.3'))->inc('prerelease')->raw);
        self::assertSame('1.2.3-beta.2', (new SemVer('1.2.3-beta.1'))->inc('prerelease')->raw);
        self::assertSame('5.56.1', (new SemVer('5.56.0.1'))->inc('patch')->raw);
    }

    public function testCompare(): void
    {
        $sorted = ['1.2.3-alpha', '1.2.3-beta.1', '1.2.3-beta.2', '1.2.3-beta.10', '1.2.3', '1.2.3.1', '1.2.3.2', '1.2.4-beta.1', '1.2.4'];
        $shuffled = $sorted;
        shuffle($shuffled);
        usort($shuffled, static fn (string $a, string $b): int => (new SemVer($a))->compare($b));

        self::assertSame($sorted, $shuffled);
        self::assertSame(0, (new SemVer('v1.2.3'))->compare('1.2.3'));
        self::assertSame(0, (new SemVer('1.2.3+build.1'))->compare('1.2.3'));
    }

    public function testFourthNumber(): void
    {
        $version = new SemVer('5.56.0.1');

        self::assertSame(1, $version->revision);
        self::assertSame('5.56.0.1', $version->version);
        self::assertTrue(Range::satisfies('5.56.0.1', '>5.56.0 <=5.56.0.2'));
        self::assertTrue(Range::satisfies('5.56.0.1', '^5.56'));
        self::assertFalse(Range::satisfies('5.56.0.1', '5.56.0'));
        self::assertNull((new SemVer('5.56.0'))->revision);
    }
}
