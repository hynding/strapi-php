<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Version;

/**
 * Port of packages/utils/upgrade/src/modules/version/types.ts.
 *
 * `Version.SemVer` / `Version.Range` are the `semver` package classes, ported in `node-semver/`.
 *
 * @phpstan-type ReleaseType 'major'|'minor'|'patch'|'latest'
 * @phpstan-type LiteralSemVer string
 */
final class Types
{
    // Classic
    public const MAJOR = 'major';
    public const MINOR = 'minor';
    public const PATCH = 'patch';
    // Other
    public const LATEST = 'latest';

    /** upstream's `RELEASE_TYPES` (`Version.RELEASE_TYPES.Major` is `Types::RELEASE_TYPES['Major']`) */
    public const RELEASE_TYPES = [
        'Major' => self::MAJOR,
        'Minor' => self::MINOR,
        'Patch' => self::PATCH,
        'Latest' => self::LATEST,
    ];
}
