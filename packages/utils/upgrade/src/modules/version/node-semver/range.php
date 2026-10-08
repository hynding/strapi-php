<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Version\NodeSemver;

/**
 * Port of the npm `semver@7.7.4` package's classes/range.js (strict mode), plus the
 * ranges/valid.js, ranges/min-version.js and functions/satisfies.js helpers upstream's upgrade
 * tool and codemods call (`semver.validRange`, `semver.minVersion`, `semver.satisfies`).
 */
final class Range implements \Stringable
{
    /** the input, trimmed and with whitespace collapsed (upstream's `range.raw`) */
    public string $raw;

    /** @var list<list<Comparator>> */
    public array $set;

    private ?string $formatted = null;

    public function __construct(Range|Comparator|string $range)
    {
        if ($range instanceof Range) {
            $range = $range->raw;
        }

        if ($range instanceof Comparator) {
            $this->raw = $range->value;
            $this->set = [[$range]];

            return;
        }

        // First reduce all whitespace as much as possible so we do not have to rely
        // on potentially slow regexes like \s*.
        $this->raw = (string) preg_replace('/\s+/', ' ', trim($range));

        // First, split on ||, map the range to a 2d array of comparators and throw out any
        // comparator lists that are empty
        $set = [];
        foreach (explode('||', $this->raw) as $r) {
            $comparators = $this->parseRange(trim($r));
            if ($comparators !== []) {
                $set[] = $comparators;
            }
        }

        if ($set === []) {
            throw new \InvalidArgumentException("Invalid SemVer Range: {$this->raw}");
        }

        // if we have any that are not the null set, throw out null sets.
        if (count($set) > 1) {
            // keep the first one, in case they're all null sets
            $first = $set[0];
            $set = array_values(array_filter($set, static fn (array $c): bool => !self::isNullSet($c[0])));
            if ($set === []) {
                $set = [$first];
            } elseif (count($set) > 1) {
                // if we have any that are *, then the range is just *
                foreach ($set as $c) {
                    if (count($c) === 1 && self::isAny($c[0])) {
                        $set = [$c];
                        break;
                    }
                }
            }
        }

        $this->set = $set;
    }

    public function range(): string
    {
        if ($this->formatted === null) {
            $this->formatted = implode('||', array_map(
                static fn (array $comps): string => implode(' ', array_map(static fn (Comparator $c): string => trim((string) $c), $comps)),
                $this->set
            ));
        }

        return $this->formatted;
    }

    public function format(): string
    {
        return $this->range();
    }

    public function __toString(): string
    {
        return $this->range();
    }

    /** @return list<Comparator> */
    private function parseRange(string $range): array
    {
        // `1.2.3 - 1.2.4` => `>=1.2.3 <=1.2.4`
        $range = (string) preg_replace_callback(Re::HYPHENRANGE, self::hyphenReplace(...), $range);

        // `> 1.2.3 < 1.2.5` => `>1.2.3 <1.2.5`
        $range = (string) preg_replace(Re::COMPARATORTRIM, '$1$2$3', $range);

        // `~ 1.2.3` => `~1.2.3`
        $range = (string) preg_replace(Re::TILDETRIM, '$1~', $range);

        // `^ 1.2.3` => `^1.2.3`
        $range = (string) preg_replace(Re::CARETTRIM, '$1^', $range);

        // At this point, the range is completely trimmed and ready to be split into comparators.
        $parsed = implode(' ', array_map(self::parseComparator(...), explode(' ', $range)));
        $rangeList = array_map(self::replaceGTE0(...), preg_split('/\s+/', $parsed) ?: []);

        // if any comparators are the null set, then replace with JUST null set
        // if more than one comparator, remove any * comparators
        // also, don't include the same comparator more than once
        /** @var array<string, Comparator> $rangeMap */
        $rangeMap = [];
        foreach ($rangeList as $comp) {
            $comparator = new Comparator($comp);
            if (self::isNullSet($comparator)) {
                return [$comparator];
            }
            $rangeMap[$comparator->value] = $comparator;
        }
        if (count($rangeMap) > 1 && isset($rangeMap[''])) {
            unset($rangeMap['']);
        }

        return array_values($rangeMap);
    }

    /** if ANY of the sets match ALL of its comparators, then pass */
    public function test(SemVer|string|null $version): bool
    {
        if ($version === null || $version === '') {
            return false;
        }

        if (is_string($version)) {
            try {
                $version = new SemVer($version);
            } catch (\InvalidArgumentException) {
                return false;
            }
        }

        foreach ($this->set as $comparators) {
            if (self::testSet($comparators, $version)) {
                return true;
            }
        }

        return false;
    }

    public function intersects(Range $range): bool
    {
        foreach ($this->set as $thisComparators) {
            if (!self::isSatisfiable($thisComparators)) {
                continue;
            }
            foreach ($range->set as $rangeComparators) {
                if (!self::isSatisfiable($rangeComparators)) {
                    continue;
                }
                $all = true;
                foreach ($thisComparators as $thisComparator) {
                    foreach ($rangeComparators as $rangeComparator) {
                        if (!$thisComparator->intersects($rangeComparator)) {
                            $all = false;
                            break 2;
                        }
                    }
                }
                if ($all) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Port of ranges/valid.js: the normalized range, `'*'` for "any", or null when invalid. */
    public static function validRange(string $range): ?string
    {
        try {
            $formatted = (new Range($range))->range();

            return $formatted !== '' ? $formatted : '*';
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** Port of functions/satisfies.js. */
    public static function satisfies(SemVer|string $version, Range|string $range): bool
    {
        try {
            $range = $range instanceof Range ? $range : new Range($range);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $range->test($version);
    }

    /** Port of ranges/min-version.js. */
    public static function minVersion(Range|string $range): ?SemVer
    {
        $range = $range instanceof Range ? $range : new Range($range);

        $minver = new SemVer('0.0.0');
        if ($range->test($minver)) {
            return $minver;
        }

        $minver = new SemVer('0.0.0-0');
        if ($range->test($minver)) {
            return $minver;
        }

        $minver = null;
        foreach ($range->set as $comparators) {
            $setMin = null;
            foreach ($comparators as $comparator) {
                if ($comparator->semver === null) {
                    // `ANY` has no version; upstream's `new SemVer(ANY.version)` would throw
                    continue;
                }
                // Clone to avoid manipulating the comparator's semver object.
                $compver = new SemVer($comparator->semver->version);
                switch ($comparator->operator) {
                    case '>':
                        if ($compver->prerelease === []) {
                            ++$compver->patch;
                        } else {
                            $compver->prerelease[] = 0;
                        }
                        $compver->raw = $compver->format();
                        // fallthrough
                    case '':
                    case '>=':
                        if ($setMin === null || Comparator::cmp($compver, '>', $setMin)) {
                            $setMin = $compver;
                        }
                        break;
                    case '<':
                    case '<=':
                        // Ignore maximum versions
                        break;
                    default:
                        throw new \LogicException("Unexpected operation: {$comparator->operator}");
                }
            }
            if ($setMin !== null && ($minver === null || Comparator::cmp($minver, '>', $setMin))) {
                $minver = $setMin;
            }
        }

        if ($minver !== null && $range->test($minver)) {
            return $minver;
        }

        return null;
    }

    private static function isNullSet(Comparator $c): bool
    {
        return $c->value === '<0.0.0-0';
    }

    private static function isAny(Comparator $c): bool
    {
        return $c->value === '';
    }

    /**
     * take a set of comparators and determine whether there exists a version which can satisfy it
     *
     * @param list<Comparator> $comparators
     */
    private static function isSatisfiable(array $comparators): bool
    {
        $result = true;
        $remaining = $comparators;
        $testComparator = array_pop($remaining);

        while ($result && $remaining !== [] && $testComparator !== null) {
            foreach ($remaining as $other) {
                if (!$testComparator->intersects($other)) {
                    $result = false;
                    break;
                }
            }
            $testComparator = array_pop($remaining);
        }

        return $result;
    }

    /**
     * comprised of xranges, tildes, stars, and gtlt's at this point.
     * already replaced the hyphen ranges
     * turn into a set of JUST comparators.
     */
    private static function parseComparator(string $comp): string
    {
        $comp = (string) preg_replace(Re::BUILD_RE, '', $comp, 1);
        $comp = self::replaceCarets($comp);
        $comp = self::replaceTildes($comp);
        $comp = self::replaceXRanges($comp);

        return self::replaceStars($comp);
    }

    private static function isX(?string $id): bool
    {
        return $id === null || $id === '' || strtolower($id) === 'x' || $id === '*';
    }

    /** @return list<string> */
    private static function splitSpaces(string $comp): array
    {
        return preg_split('/\s+/', trim($comp)) ?: [];
    }

    // ~, ~> --> * (any, kinda silly)
    // ~2, ~2.x, ~2.x.x, ~>2, ~>2.x ~>2.x.x --> >=2.0.0 <3.0.0-0
    // ~2.0, ~2.0.x, ~>2.0, ~>2.0.x --> >=2.0.0 <2.1.0-0
    // ~1.2, ~1.2.x, ~>1.2, ~>1.2.x --> >=1.2.0 <1.3.0-0
    // ~1.2.3, ~>1.2.3 --> >=1.2.3 <1.3.0-0
    // ~1.2.0, ~>1.2.0 --> >=1.2.0 <1.3.0-0
    // ~0.0.1 --> >=0.0.1 <0.1.0-0
    private static function replaceTildes(string $comp): string
    {
        return implode(' ', array_map(static fn (string $c): string => (string) preg_replace_callback(Re::TILDE, static function (array $m): string {
            [$M, $mi, $p, $pr] = [$m[1], $m[2] ?? '', $m[3] ?? '', $m[4] ?? ''];

            return match (true) {
                self::isX($M) => '',
                self::isX($mi) => ">={$M}.0.0 <" . ((int) $M + 1) . '.0.0-0',
                // ~1.2 == >=1.2.0 <1.3.0-0
                self::isX($p) => ">={$M}.{$mi}.0 <{$M}." . ((int) $mi + 1) . '.0-0',
                $pr !== '' => ">={$M}.{$mi}.{$p}-{$pr} <{$M}." . ((int) $mi + 1) . '.0-0',
                // ~1.2.3 == >=1.2.3 <1.3.0-0
                default => ">={$M}.{$mi}.{$p} <{$M}." . ((int) $mi + 1) . '.0-0',
            };
        }, $c), self::splitSpaces($comp)));
    }

    // ^ --> * (any, kinda silly)
    // ^2, ^2.x, ^2.x.x --> >=2.0.0 <3.0.0-0
    // ^2.0, ^2.0.x --> >=2.0.0 <3.0.0-0
    // ^1.2, ^1.2.x --> >=1.2.0 <2.0.0-0
    // ^1.2.3 --> >=1.2.3 <2.0.0-0
    // ^1.2.0 --> >=1.2.0 <2.0.0-0
    // ^0.0.1 --> >=0.0.1 <0.0.2-0
    // ^0.1.0 --> >=0.1.0 <0.2.0-0
    private static function replaceCarets(string $comp): string
    {
        return implode(' ', array_map(static fn (string $c): string => (string) preg_replace_callback(Re::CARET, static function (array $m): string {
            [$M, $mi, $p, $pr] = [$m[1], $m[2] ?? '', $m[3] ?? '', $m[4] ?? ''];

            if (self::isX($M)) {
                return '';
            }
            if (self::isX($mi)) {
                return ">={$M}.0.0 <" . ((int) $M + 1) . '.0.0-0';
            }
            if (self::isX($p)) {
                return $M === '0'
                    ? ">={$M}.{$mi}.0 <{$M}." . ((int) $mi + 1) . '.0-0'
                    : ">={$M}.{$mi}.0 <" . ((int) $M + 1) . '.0.0-0';
            }
            if ($pr !== '') {
                if ($M === '0') {
                    return $mi === '0'
                        ? ">={$M}.{$mi}.{$p}-{$pr} <{$M}.{$mi}." . ((int) $p + 1) . '-0'
                        : ">={$M}.{$mi}.{$p}-{$pr} <{$M}." . ((int) $mi + 1) . '.0-0';
                }

                return ">={$M}.{$mi}.{$p}-{$pr} <" . ((int) $M + 1) . '.0.0-0';
            }
            if ($M === '0') {
                return $mi === '0'
                    ? ">={$M}.{$mi}.{$p} <{$M}.{$mi}." . ((int) $p + 1) . '-0'
                    : ">={$M}.{$mi}.{$p} <{$M}." . ((int) $mi + 1) . '.0-0';
            }

            return ">={$M}.{$mi}.{$p} <" . ((int) $M + 1) . '.0.0-0';
        }, $c), self::splitSpaces($comp)));
    }

    private static function replaceXRanges(string $comp): string
    {
        return implode(' ', array_map(self::replaceXRange(...), preg_split('/\s+/', $comp) ?: []));
    }

    private static function replaceXRange(string $comp): string
    {
        $comp = trim($comp);

        return (string) preg_replace_callback(Re::XRANGE, static function (array $m): string {
            $ret = $m[0];
            $gtlt = $m[1];
            [$M, $mi, $p] = [$m[2], $m[3] ?? '', $m[4] ?? ''];

            $xM = self::isX($M);
            $xm = $xM || self::isX($mi);
            $xp = $xm || self::isX($p);
            $anyX = $xp;

            if ($gtlt === '=' && $anyX) {
                $gtlt = '';
            }

            $pr = '';

            if ($xM) {
                // `>*` / `<*`: nothing is allowed; anything else: nothing is forbidden
                $ret = $gtlt === '>' || $gtlt === '<' ? '<0.0.0-0' : '*';
            } elseif ($gtlt !== '' && $anyX) {
                // we know patch is an x, because we have any x at all. replace X with 0
                if ($xm) {
                    $mi = '0';
                }
                $p = '0';

                if ($gtlt === '>') {
                    // >1 => >=2.0.0
                    // >1.2 => >=1.3.0
                    $gtlt = '>=';
                    if ($xm) {
                        $M = (string) ((int) $M + 1);
                        $mi = '0';
                    } else {
                        $mi = (string) ((int) $mi + 1);
                    }
                    $p = '0';
                } elseif ($gtlt === '<=') {
                    // <=0.7.x is actually <0.8.0, since any 0.7.x should pass.
                    // Similarly, <=7.x is actually <8.0.0, etc.
                    $gtlt = '<';
                    if ($xm) {
                        $M = (string) ((int) $M + 1);
                    } else {
                        $mi = (string) ((int) $mi + 1);
                    }
                }

                if ($gtlt === '<') {
                    $pr = '-0';
                }

                $ret = "{$gtlt}{$M}.{$mi}.{$p}{$pr}";
            } elseif ($xm) {
                $ret = ">={$M}.0.0{$pr} <" . ((int) $M + 1) . '.0.0-0';
            } elseif ($xp) {
                $ret = ">={$M}.{$mi}.0{$pr} <{$M}." . ((int) $mi + 1) . '.0-0';
            }

            return $ret;
        }, $comp);
    }

    // Because * is AND-ed with everything else in the comparator,
    // and '' means "any version", just remove the *s entirely.
    private static function replaceStars(string $comp): string
    {
        return (string) preg_replace(Re::STAR, '', trim($comp), 1);
    }

    private static function replaceGTE0(string $comp): string
    {
        return (string) preg_replace(Re::GTE0, '', trim($comp));
    }

    // 1.2 - 3.4.5 => >=1.2.0 <=3.4.5
    // 1.2.3 - 3.4 => >=1.2.0 <3.5.0-0 Any 3.4.x will do
    // 1.2 - 3.4 => >=1.2.0 <3.5.0-0
    /** @param array<int, string> $m */
    private static function hyphenReplace(array $m): string
    {
        $from = $m[1] ?? '';
        [$fM, $fm, $fp, $fpr] = [$m[2] ?? '', $m[3] ?? '', $m[4] ?? '', $m[5] ?? ''];
        $to = $m[7] ?? '';
        [$tM, $tm, $tp, $tpr] = [$m[8] ?? '', $m[9] ?? '', $m[10] ?? '', $m[11] ?? ''];

        if (self::isX($fM)) {
            $from = '';
        } elseif (self::isX($fm)) {
            $from = ">={$fM}.0.0";
        } elseif (self::isX($fp)) {
            $from = ">={$fM}.{$fm}.0";
        } elseif ($fpr !== '') {
            $from = ">={$from}";
        } else {
            $from = ">={$from}";
        }

        if (self::isX($tM)) {
            $to = '';
        } elseif (self::isX($tm)) {
            $to = '<' . ((int) $tM + 1) . '.0.0-0';
        } elseif (self::isX($tp)) {
            $to = "<{$tM}." . ((int) $tm + 1) . '.0-0';
        } elseif ($tpr !== '') {
            $to = "<={$tM}.{$tm}.{$tp}-{$tpr}";
        } else {
            $to = "<={$to}";
        }

        return trim("{$from} {$to}");
    }

    /** @param list<Comparator> $set */
    private static function testSet(array $set, SemVer $version): bool
    {
        foreach ($set as $comparator) {
            if (!$comparator->test($version)) {
                return false;
            }
        }

        if ($version->prerelease !== []) {
            // Find the set of versions that are allowed to have prereleases
            // For example, ^1.2.3-pr.1 desugars to >=1.2.3-pr.1 <2.0.0
            // That should allow `1.2.3-pr.2` to pass.
            // However, `1.2.4-alpha.notready` should NOT be allowed,
            // even though it's within the range set by the comparators.
            foreach ($set as $comparator) {
                if ($comparator->semver === null) {
                    continue;
                }

                if ($comparator->semver->prerelease !== []) {
                    $allowed = $comparator->semver;
                    if ($allowed->major === $version->major && $allowed->minor === $version->minor && $allowed->patch === $version->patch) {
                        return true;
                    }
                }
            }

            // Version has a -pre, but it's not one of the ones we like.
            return false;
        }

        return true;
    }
}
