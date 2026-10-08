<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Version;

use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer as NodeSemVer;

/**
 * Port of packages/utils/upgrade/src/modules/version/semver.ts.
 *
 * PHP-only: `isLiteralVersion()`, the VERSIONING.md counterpart of `isLiteralSemVer()` that also
 * accepts the fourth number of a PHP-only fix release (`5.56.0.1`).
 */
final class Semver
{
    public static function semVerFactory(string $version): NodeSemVer
    {
        return new NodeSemVer($version);
    }

    /** `<number>.<number>.<number>`, nothing else (no pre-release, no `v`) */
    public static function isLiteralSemVer(string $str): bool
    {
        $tokens = explode('.', $str);

        return count($tokens) === 3 && self::allIntegers($tokens);
    }

    /** `isLiteralSemVer()` or `<number>.<number>.<number>.<number>` (VERSIONING.md) */
    public static function isLiteralVersion(string $str): bool
    {
        $tokens = explode('.', $str);

        return (count($tokens) === 3 || count($tokens) === 4) && self::allIntegers($tokens);
    }

    /** @param list<string> $tokens */
    private static function allIntegers(array $tokens): bool
    {
        foreach ($tokens as $token) {
            // JS `Number.isInteger(+token)` (`+''` is 0, `+' 1e2 '` is 100)
            $trimmed = trim($token);
            if ($trimmed !== '' && (!is_numeric($trimmed) || floor((float) $trimmed) !== (float) $trimmed)) {
                return false;
            }
        }

        return true;
    }

    public static function isValidSemVer(string $str): bool
    {
        try {
            new NodeSemVer($str);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public static function isSemverInstance(mixed $value): bool
    {
        return $value instanceof NodeSemVer;
    }

    public static function isSemVerReleaseType(string $str): bool
    {
        return in_array($str, Types::RELEASE_TYPES, true);
    }
}
