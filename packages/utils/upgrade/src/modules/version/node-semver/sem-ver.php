<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Version\NodeSemver;

/**
 * Port of the npm `semver@7.7.4` package's classes/semver.js (strict mode), the `SemVer` type
 * upstream's upgrade tool builds every version from.
 *
 * PHP-only (VERSIONING.md): an optional fourth number, `$revision`, for PHP-only fix releases
 * (`5.56.0.1`). It compares after `patch` (`5.56.0 < 5.56.0.1 < 5.56.1`), is part of `version`
 * and `raw`, and is dropped by every `inc()`. npm versions never have one.
 */
final class SemVer implements \Stringable
{
    public string $raw;

    public int $major;

    public int $minor;

    public int $patch;

    /** PHP-only fourth number; null when absent */
    public ?int $revision;

    /** @var list<int|string> */
    public array $prerelease;

    /** @var list<string> */
    public array $build;

    public string $version;

    public function __construct(SemVer|string $version)
    {
        if ($version instanceof SemVer) {
            $version = $version->version;
        }

        if (strlen($version) > Re::MAX_LENGTH) {
            throw new \InvalidArgumentException('version is longer than ' . Re::MAX_LENGTH . ' characters');
        }

        if (preg_match(Re::FULL, trim($version), $m) !== 1) {
            throw new \InvalidArgumentException("Invalid Version: {$version}");
        }

        $this->raw = $version;

        $this->major = self::toInt($m[1], 'major');
        $this->minor = self::toInt($m[2], 'minor');
        $this->patch = self::toInt($m[3], 'patch');
        // an optional group that didn't match is '' (or absent when no later group matched)
        $revision = $m[4] ?? '';
        $prerelease = $m[5] ?? '';
        $build = $m[6] ?? '';

        $this->revision = $revision !== '' ? self::toInt($revision, 'revision') : null;

        $this->prerelease = $prerelease !== ''
            ? array_map(static fn (string $id): int|string => preg_match('/^[0-9]+$/', $id) === 1 && (float) $id < PHP_INT_MAX ? (int) $id : $id, explode('.', $prerelease))
            : [];

        $this->build = $build !== '' ? explode('.', $build) : [];

        $this->format();
    }

    private static function toInt(string $value, string $label): int
    {
        if ((float) $value > PHP_INT_MAX) {
            throw new \InvalidArgumentException("Invalid {$label} version");
        }

        return (int) $value;
    }

    public function format(): string
    {
        $this->version = "{$this->major}.{$this->minor}.{$this->patch}";
        if ($this->revision !== null) {
            $this->version .= ".{$this->revision}";
        }
        if ($this->prerelease !== []) {
            $this->version .= '-' . implode('.', $this->prerelease);
        }

        return $this->version;
    }

    public function __toString(): string
    {
        return $this->version;
    }

    public function compare(SemVer|string $other): int
    {
        if (!$other instanceof SemVer) {
            if ($other === $this->version) {
                return 0;
            }
            $other = new SemVer($other);
        }

        if ($other->version === $this->version) {
            return 0;
        }

        return $this->compareMain($other) ?: $this->comparePre($other);
    }

    public function compareMain(SemVer|string $other): int
    {
        if (!$other instanceof SemVer) {
            $other = new SemVer($other);
        }

        return [$this->major, $this->minor, $this->patch, $this->revision ?? 0]
            <=> [$other->major, $other->minor, $other->patch, $other->revision ?? 0];
    }

    public function comparePre(SemVer|string $other): int
    {
        if (!$other instanceof SemVer) {
            $other = new SemVer($other);
        }

        // NOT having a prerelease is > having one
        if ($this->prerelease !== [] && $other->prerelease === []) {
            return -1;
        }
        if ($this->prerelease === [] && $other->prerelease !== []) {
            return 1;
        }
        if ($this->prerelease === [] && $other->prerelease === []) {
            return 0;
        }

        return self::compareLists($this->prerelease, $other->prerelease);
    }

    public function compareBuild(SemVer|string $other): int
    {
        if (!$other instanceof SemVer) {
            $other = new SemVer($other);
        }

        return self::compareLists($this->build, $other->build);
    }

    /**
     * @param list<int|string> $a
     * @param list<int|string> $b
     */
    private static function compareLists(array $a, array $b): int
    {
        for ($i = 0; ; ++$i) {
            $x = $a[$i] ?? null;
            $y = $b[$i] ?? null;
            if ($x === null && $y === null) {
                return 0;
            }
            if ($y === null) {
                return 1;
            }
            if ($x === null) {
                return -1;
            }
            if ($x === $y) {
                continue;
            }

            return self::compareIdentifiers($x, $y);
        }
    }

    /** Port of internal/identifiers.js `compareIdentifiers`. */
    public static function compareIdentifiers(int|string $a, int|string $b): int
    {
        if (is_int($a) && is_int($b)) {
            return $a <=> $b;
        }

        $anum = preg_match('/^[0-9]+$/', (string) $a) === 1;
        $bnum = preg_match('/^[0-9]+$/', (string) $b) === 1;

        if ($anum && $bnum) {
            return (int) $a <=> (int) $b;
        }

        if ((string) $a === (string) $b) {
            return 0;
        }

        return match (true) {
            $anum && !$bnum => -1,
            $bnum && !$anum => 1,
            default => strcmp((string) $a, (string) $b) < 0 ? -1 : 1,
        };
    }

    /**
     * Increments the version in place. `preminor` will bump the version up to the next minor
     * release, and immediately down to pre-release; `premajor` and `prepatch` work the same way.
     */
    public function inc(string $release, ?string $identifier = null, string|false|null $identifierBase = null): self
    {
        if (str_starts_with($release, 'pre')) {
            if (($identifier === null || $identifier === '') && $identifierBase === false) {
                throw new \InvalidArgumentException('invalid increment argument: identifier is empty');
            }
            // Avoid an invalid semver results
            if ($identifier !== null && $identifier !== '') {
                if (preg_match(Re::PRERELEASE_RE, "-{$identifier}", $match) !== 1 || $match[1] !== $identifier) {
                    throw new \InvalidArgumentException("invalid identifier: {$identifier}");
                }
            }
        }

        switch ($release) {
            case 'premajor':
                $this->prerelease = [];
                $this->revision = null;
                $this->patch = 0;
                $this->minor = 0;
                ++$this->major;
                $this->inc('pre', $identifier, $identifierBase);
                break;
            case 'preminor':
                $this->prerelease = [];
                $this->revision = null;
                $this->patch = 0;
                ++$this->minor;
                $this->inc('pre', $identifier, $identifierBase);
                break;
            case 'prepatch':
                // If this is already a prerelease, it will bump to the next version
                // drop any prereleases that might already exist, since they are not
                // relevant at this point.
                $this->prerelease = [];
                $this->inc('patch', $identifier, $identifierBase);
                $this->inc('pre', $identifier, $identifierBase);
                break;
            // If the input is a non-prerelease version, this acts the same as prepatch.
            case 'prerelease':
                if ($this->prerelease === []) {
                    $this->inc('patch', $identifier, $identifierBase);
                }
                $this->inc('pre', $identifier, $identifierBase);
                break;
            case 'release':
                if ($this->prerelease === []) {
                    throw new \InvalidArgumentException("version {$this->raw} is not a prerelease");
                }
                $this->prerelease = [];
                break;
            case 'major':
                // If this is a pre-major version, bump up to the same major version.
                // Otherwise increment major.
                // 1.0.0-5 bumps to 1.0.0
                // 1.1.0 bumps to 2.0.0
                if ($this->minor !== 0 || $this->patch !== 0 || ($this->revision ?? 0) !== 0 || $this->prerelease === []) {
                    ++$this->major;
                }
                $this->minor = 0;
                $this->patch = 0;
                $this->revision = null;
                $this->prerelease = [];
                break;
            case 'minor':
                // If this is a pre-minor version, bump up to the same minor version.
                // Otherwise increment minor.
                // 1.2.0-5 bumps to 1.2.0
                // 1.2.1 bumps to 1.3.0
                if ($this->patch !== 0 || ($this->revision ?? 0) !== 0 || $this->prerelease === []) {
                    ++$this->minor;
                }
                $this->patch = 0;
                $this->revision = null;
                $this->prerelease = [];
                break;
            case 'patch':
                // If this is not a pre-release version, it will increment the patch.
                // If it is a pre-release it will bump up to the same patch version.
                // 1.2.0-5 patches to 1.2.0
                // 1.2.0 patches to 1.2.1
                if ($this->prerelease === []) {
                    ++$this->patch;
                }
                $this->revision = null;
                $this->prerelease = [];
                break;
            // This probably shouldn't be used publicly.
            // 1.0.0 'pre' would become 1.0.0-0 which is the wrong direction.
            case 'pre':
                $base = is_numeric($identifierBase) && (int) $identifierBase !== 0 ? 1 : 0;

                if ($this->prerelease === []) {
                    $this->prerelease = [$base];
                } else {
                    $i = count($this->prerelease);
                    while (--$i >= 0) {
                        if (is_int($this->prerelease[$i])) {
                            ++$this->prerelease[$i];
                            $i = -2;
                        }
                    }
                    if ($i === -1) {
                        // didn't increment anything
                        if ($identifier === implode('.', $this->prerelease) && $identifierBase === false) {
                            throw new \InvalidArgumentException('invalid increment argument: identifier already exists');
                        }
                        $this->prerelease[] = $base;
                    }
                }
                if ($identifier !== null && $identifier !== '') {
                    // 1.2.0-beta.1 bumps to 1.2.0-beta.2,
                    // 1.2.0-beta.fooblz or 1.2.0-beta bumps to 1.2.0-beta.0
                    $prerelease = $identifierBase === false ? [$identifier] : [$identifier, $base];
                    if (self::compareIdentifiers($this->prerelease[0], $identifier) === 0) {
                        if (!is_int($this->prerelease[1] ?? null)) {
                            $this->prerelease = $prerelease;
                        }
                    } else {
                        $this->prerelease = $prerelease;
                    }
                }
                break;
            default:
                throw new \InvalidArgumentException("invalid increment argument: {$release}");
        }

        $this->raw = $this->format();
        if ($this->build !== []) {
            $this->raw .= '+' . implode('.', $this->build);
        }

        return $this;
    }
}
