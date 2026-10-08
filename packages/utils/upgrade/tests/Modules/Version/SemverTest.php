<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests\Modules\Version;

use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer as NodeSemVer;
use Strapi\Upgrade\Modules\Version\Semver;
use Strapi\Upgrade\Modules\Version\Types;
use Strapi\Upgrade\Tests\TestCase;

/** Port of src/modules/version/__tests__/semver.test.ts. */
final class SemverTest extends TestCase
{
    public function testSemVerFactoryCreatesASemVerInstance(): void
    {
        self::assertInstanceOf(NodeSemVer::class, Semver::semVerFactory('1.0.0'));
    }

    public function testIsLiteralSemVer(): void
    {
        self::assertTrue(Semver::isLiteralSemVer('1.0.0'));
        self::assertTrue(Semver::isLiteralSemVer('0.0.1'));

        self::assertFalse(Semver::isLiteralSemVer('1.0'));
        self::assertFalse(Semver::isLiteralSemVer('1.0.x'));
        self::assertFalse(Semver::isLiteralSemVer('test'));
        self::assertFalse(Semver::isLiteralSemVer('5.56.0.1'));
        self::assertFalse(Semver::isLiteralSemVer('5.56.0-beta.1'));
    }

    public function testIsLiteralVersionAcceptsTheFourthNumber(): void
    {
        self::assertTrue(Semver::isLiteralVersion('5.56.0'));
        self::assertTrue(Semver::isLiteralVersion('5.56.0.1'));
        self::assertFalse(Semver::isLiteralVersion('5.56.0-beta.1'));
    }

    public function testIsSemverInstance(): void
    {
        self::assertTrue(Semver::isSemverInstance(new NodeSemVer('1.0.0')));
        self::assertFalse(Semver::isSemverInstance(new \stdClass()));
        self::assertFalse(Semver::isSemverInstance('1.0.0'));
    }

    public function testIsSemVerReleaseType(): void
    {
        foreach (Types::RELEASE_TYPES as $type) {
            self::assertTrue(Semver::isSemVerReleaseType($type));
        }
        self::assertFalse(Semver::isSemVerReleaseType('invalid'));
    }
}
