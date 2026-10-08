<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Version\NodeSemver;

/**
 * Port of the npm `semver@7.7.4` package's internal/re.js (strict mode only: upstream's upgrade
 * tool never passes `loose` or `includePrerelease`).
 *
 * One PHP-only change, from VERSIONING.md: a version may carry a fourth numeric component
 * (`5.56.0.1`, a PHP-only fix release, sorting after `5.56.0`). It is accepted wherever a full
 * version is (`FULL`, `COMPARATOR`), not in x-ranges, carets, tildes or hyphen ranges, so it
 * shifts the pre-release and build capture groups of `FULLPLAIN` by one (see below).
 */
final class Re
{
    public const MAX_LENGTH = 256;
    public const MAX_SAFE_BUILD_LENGTH = self::MAX_LENGTH - 6;

    private const NUMERICIDENTIFIER = '0|[1-9]\d*';
    private const NONNUMERICIDENTIFIER = '\d*[a-zA-Z-][a-zA-Z0-9-]*';
    private const PRERELEASEIDENTIFIER = '(?:' . self::NONNUMERICIDENTIFIER . '|' . self::NUMERICIDENTIFIER . ')';
    private const PRERELEASE = '(?:-(' . self::PRERELEASEIDENTIFIER . '(?:\.' . self::PRERELEASEIDENTIFIER . ')*))';
    private const BUILDIDENTIFIER = '[a-zA-Z0-9-]+';
    private const BUILD = '(?:\+(' . self::BUILDIDENTIFIER . '(?:\.' . self::BUILDIDENTIFIER . ')*))';
    private const XRANGEIDENTIFIER = self::NUMERICIDENTIFIER . '|x|X|\*';
    private const GTLT = '((?:<|>)?=?)';
    /** the PHP-only fourth number (VERSIONING.md), optional */
    private const FOURTH = '(?:\.(' . self::NUMERICIDENTIFIER . '))?';

    /** `v?M.m.p[.r][-pre][+build]`; groups: 1 M, 2 m, 3 p, 4 r (PHP-only), 5 pre, 6 build */
    public const FULLPLAIN = 'v?(' . self::NUMERICIDENTIFIER . ')\.(' . self::NUMERICIDENTIFIER . ')\.(' . self::NUMERICIDENTIFIER . ')'
        . self::FOURTH . self::PRERELEASE . '?' . self::BUILD . '?';

    public const FULL = '/^' . self::FULLPLAIN . '$/';

    public const XRANGEPLAIN = '[v=\s]*(' . self::XRANGEIDENTIFIER . ')'
        . '(?:\.(' . self::XRANGEIDENTIFIER . ')'
        . '(?:\.(' . self::XRANGEIDENTIFIER . ')'
        . '(?:' . self::PRERELEASE . ')?' . self::BUILD . '?'
        . ')?)?';

    public const XRANGE = '/^' . self::GTLT . '\s*' . self::XRANGEPLAIN . '$/';

    public const LONETILDE = '(?:~>?)';
    public const TILDETRIM = '/(\s*)' . self::LONETILDE . '\s+/';
    public const TILDE = '/^' . self::LONETILDE . self::XRANGEPLAIN . '$/';

    public const LONECARET = '(?:\^)';
    public const CARETTRIM = '/(\s*)' . self::LONECARET . '\s+/';
    public const CARET = '/^' . self::LONECARET . self::XRANGEPLAIN . '$/';

    /** groups: 1 operator, 2 version, then FULLPLAIN's groups shifted by 2 */
    public const COMPARATOR = '/^' . self::GTLT . '\s*(' . self::FULLPLAIN . ')$|^$/';

    /** upstream uses LOOSEPLAIN|XRANGEPLAIN here; strict input never needs the loose form */
    public const COMPARATORTRIM = '/(\s*)' . self::GTLT . '\s*(' . self::FULLPLAIN . '|' . self::XRANGEPLAIN . ')/';

    public const HYPHENRANGE = '/^\s*(' . self::XRANGEPLAIN . ')\s+-\s+(' . self::XRANGEPLAIN . ')\s*$/';

    public const STAR = '/(<|>)?=?\s*\*/';
    public const GTE0 = '/^\s*>=\s*0\.0\.0\s*$/';

    /** used by `parseComparator` to strip build metadata */
    public const BUILD_RE = '/' . self::BUILD . '/';

    /** `-<identifier>` validation for `inc('pre…', identifier)` */
    public const PRERELEASE_RE = '/' . self::PRERELEASE . '/';
}
