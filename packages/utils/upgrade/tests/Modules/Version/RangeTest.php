<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\Version;

use PHPUnit\Framework\Attributes\DataProvider;
use Strapi\Upgrade\Modules\Version\NodeSemver\Range as SemverRange;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer as NodeSemVer;
use Strapi\Upgrade\Modules\Version\Range;
use Strapi\Upgrade\Modules\Version\Types;
use Strapi\Upgrade\Tests\TestCase;

/** Port of src/modules/version/__tests__/range.test.ts. */
final class RangeTest extends TestCase
{
    public function testRangeFactoryCreatesASemverRangeWithTheGivenRangeString(): void
    {
        $range = Range::rangeFactory('>=1.0.0 <2.0.0');

        self::assertInstanceOf(SemverRange::class, $range);
        self::assertSame('>=1.0.0 <2.0.0', $range->raw);
    }

    /** @return iterable<string, array{string, string}> */
    public static function releaseTypes(): iterable
    {
        yield 'major' => [Types::MAJOR, '>1.0.0 <=2'];
        yield 'minor' => [Types::MINOR, '>1.0.0 <2.0.0'];
        yield 'patch' => [Types::PATCH, '>1.0.0 <1.1.0'];
        yield 'latest' => [Types::LATEST, '>1.0.0'];
    }

    #[DataProvider('releaseTypes')]
    public function testRangeFromReleaseType(string $releaseType, string $expected): void
    {
        $range = Range::rangeFromReleaseType(new NodeSemVer('1.0.0'), $releaseType);

        self::assertInstanceOf(SemverRange::class, $range);
        self::assertSame($expected, $range->raw);
    }

    public function testRangeFromReleaseTypeThrowsForUnsupportedReleaseTypes(): void
    {
        $this->expectExceptionMessage('Not implemented');

        Range::rangeFromReleaseType(new NodeSemVer('1.0.0'), 'unsupported');
    }

    public function testRangeFromVersionsWithASemVerTarget(): void
    {
        $range = Range::rangeFromVersions(new NodeSemVer('1.0.0'), new NodeSemVer('1.5.0'));

        self::assertSame('>1.0.0 <=1.5.0', $range->raw);
    }

    public function testRangeFromVersionsWithAReleaseType(): void
    {
        self::assertSame('>1.0.0 <=2', Range::rangeFromVersions(new NodeSemVer('1.0.0'), Types::MAJOR)->raw);
    }

    public function testRangeFromVersionsThrowsForInvalidTargets(): void
    {
        $this->expectException(\RuntimeException::class);

        Range::rangeFromVersions(new NodeSemVer('1.0.0'), 'invalid');
    }

    /** PHP-only: pre-release and fourth-number versions (VERSIONING.md) */
    public function testRangesFromStrapiPhpVersions(): void
    {
        $beta = new NodeSemVer('5.56.0-beta.1');

        // like npm semver, a pre-minor bumps to its own minor: "patch" finds nothing stable
        self::assertSame('>5.56.0-beta.1 <5.56.0', Range::rangeFromReleaseType($beta, Types::PATCH)->raw);
        self::assertTrue(Range::rangeFromReleaseType($beta, Types::MINOR)->test('5.56.0'));
        self::assertTrue(Range::rangeFromReleaseType(new NodeSemVer('5.56.0'), Types::PATCH)->test('5.56.0.1'));
        self::assertFalse(Range::rangeFromReleaseType(new NodeSemVer('5.56.0.1'), Types::PATCH)->test('5.56.0.1'));
        self::assertTrue(Range::isValidStringifiedRange('>5.56.0.1 <=5.57.0'));
    }
}
