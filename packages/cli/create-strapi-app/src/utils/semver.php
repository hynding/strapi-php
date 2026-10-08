<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Utils;

/**
 * The part of the `semver` npm package create-strapi-app uses (`coerce`, `gte`, `lt`, `major`,
 * `satisfies`), on top of `version_compare()`. No upstream file: upstream imports `semver`.
 *
 * Ranges are whitespace-separated comparators (`>=20.0.0 <=26.x.x`, `<4`, `*`), each with an
 * optional operator (`<`, `<=`, `>`, `>=`, `=`) and a possibly partial or x-version, the way
 * node-semver desugars them (`<=26.x.x` is `<27.0.0`, `<4` is `<4.0.0`, `4` is `>=4.0.0 <5.0.0`).
 * Pre-release tags are ignored.
 */
final class Semver
{
    /** `semver.coerce(v)?.version`: the first `X[.Y[.Z]]` in the string, padded with zeros. */
    public static function coerce(?string $version): ?string
    {
        if ($version === null || preg_match('/(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $version, $m) !== 1) {
            return null;
        }

        return sprintf('%d.%d.%d', (int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0));
    }

    public static function major(string $version): int
    {
        return (int) explode('.', self::coerce($version) ?? '0')[0];
    }

    public static function gte(string $a, string $b): bool
    {
        return version_compare(self::coerce($a) ?? '0.0.0', self::coerce($b) ?? '0.0.0', '>=');
    }

    public static function lt(string $a, string $b): bool
    {
        return version_compare(self::coerce($a) ?? '0.0.0', self::coerce($b) ?? '0.0.0', '<');
    }

    public static function satisfies(string $version, string $range): bool
    {
        $version = self::coerce($version);
        if ($version === null) {
            return false;
        }

        $range = trim($range);
        if ($range === '' || $range === '*' || strtolower($range) === 'x') {
            return true;
        }

        foreach (preg_split('/\s+/', $range) ?: [] as $comparator) {
            if (preg_match('/^(<=|>=|<|>|=)?v?(.+)$/', $comparator, $m) !== 1) {
                return false;
            }
            foreach (self::desugar($m[1], $m[2]) as [$op, $bound]) {
                if (!version_compare($version, $bound, $op)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * One comparator → plain `[operator, X.Y.Z]` pairs.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function desugar(string $op, string $partial): array
    {
        $parts = explode('.', explode('-', $partial)[0]);
        $numbers = [];
        foreach (array_slice($parts, 0, 3) as $part) {
            if (!ctype_digit($part)) {
                break; // x, X, * or missing: the rest is a wildcard
            }
            $numbers[] = (int) $part;
        }

        if ($numbers === []) {
            // `*`, `<x`... : anything (`<*` matches nothing in node-semver; not needed here)
            return [];
        }

        $floor = implode('.', array_pad($numbers, 3, 0));
        $exact = count($numbers) === 3;
        // the first version above the partial one: 26 → 27.0.0, 4.1 → 4.2.0
        $next = $numbers;
        $next[count($next) - 1]++;
        $ceiling = implode('.', array_pad($next, 3, 0));

        return match ($op) {
            '<' => [['<', $floor]],
            '>=' => [['>=', $floor]],
            '<=' => $exact ? [['<=', $floor]] : [['<', $ceiling]],
            '>' => $exact ? [['>', $floor]] : [['>=', $ceiling]],
            default => $exact ? [['==', $floor]] : [['>=', $floor], ['<', $ceiling]],
        };
    }
}
