<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Version;

use Strapi\Upgrade\Modules\Version\NodeSemver\Range as SemverRange;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer as NodeSemVer;

/** Port of packages/utils/upgrade/src/modules/version/range.ts. */
final class Range
{
    public static function rangeFactory(string $range): SemverRange
    {
        return new SemverRange($range);
    }

    public static function rangeFromReleaseType(NodeSemVer $current, string $identifier): SemverRange
    {
        switch ($identifier) {
            case Types::LATEST:
                // Match anything greater than the current version
                return self::rangeFactory(">{$current->raw}");
            case Types::MAJOR:
                // For example, 4.15.4 returns 5.0.0
                $nextMajor = Semver::semVerFactory($current->raw)->inc('major');

                // Using only the major version as the upper limit allows any minor,
                // patch, or build version to be taken in the range.
                //
                // For example, if the current version is "4.15.4", incrementing the
                // major version would result in "5.0.0".
                // The generated rule is ">4.15.4 <=5", allowing any version
                // greater than "4.15.4" but less than "6.0.0-0".
                return self::rangeFactory(">{$current->raw} <={$nextMajor->major}");
            case Types::MINOR:
                // For example, 4.15.4 returns 5.0.0
                $nextMajor = Semver::semVerFactory($current->raw)->inc('major');

                // Using the <major>.<minor>.<patch> version as the upper limit allows any minor,
                // patch, or build versions to be taken in the range.
                //
                // For example, if the current version is "4.15.4", incrementing the
                // major version would result in "5.0.0".
                // The generated rule is ">4.15.4 <5.0.0", allowing any version
                // greater than "4.15.4" but less than "5.0.0".
                return self::rangeFactory(">{$current->raw} <{$nextMajor->raw}");
            case Types::PATCH:
                // For example, 4.15.4 returns 4.16.0
                $nextMinor = Semver::semVerFactory($current->raw)->inc('minor');

                // Using only the minor version as the upper limit allows any patch
                // or build versions to be taken in the range.
                //
                // For example, if the current version is "4.15.4", incrementing the
                // minor version would result in "4.16.0".
                // The generated rule is ">4.15.4 <4.16.0", allowing any version
                // greater than "4.15.4" but less than "4.16.0".
                return self::rangeFactory(">{$current->raw} <{$nextMinor->raw}");
            default:
                throw new \RuntimeException('Not implemented');
        }
    }

    public static function rangeFromVersions(NodeSemVer $currentVersion, NodeSemVer|string $target): SemverRange
    {
        if ($target instanceof NodeSemVer) {
            return self::rangeFactory(">{$currentVersion->raw} <={$target->raw}");
        }

        if (Semver::isSemVerReleaseType($target)) {
            return self::rangeFromReleaseType($currentVersion, $target);
        }

        throw new \RuntimeException("Invalid target set: {$target}"); // TODO: better errors
    }

    public static function isValidStringifiedRange(string $str): bool
    {
        return SemverRange::validRange($str) !== null;
    }

    public static function isRangeInstance(mixed $range): bool
    {
        return $range instanceof SemverRange;
    }
}
