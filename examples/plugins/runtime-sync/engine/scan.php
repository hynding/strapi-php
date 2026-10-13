<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Engine;

/**
 * Mirrors engine/scan.js: classifies every project file by the spec's facets.
 *
 * @phpstan-type PairedFile array{facet: string, stem: string, node: ?string, php: ?string, state: 'paired'|'missing-node'|'missing-php'}
 * @phpstan-type ScanResult array{paired: list<PairedFile>, shared: array{count: int, byFacet: array<string, int>, missing: list<string>}, unsupported: list<string>}
 */
final class Scan
{
    /** @return ScanResult */
    public static function run(string $root): array
    {
        /** @var array<string, array{facet: string, node: ?string, php: ?string}> $pairs */
        $pairs = [];
        $byFacet = [];
        $sharedCount = 0;
        $unsupported = [];

        foreach (Files::walk($root) as $path) {
            $ext = Files::extension($path);
            $runtime = Spec::runtimeOfExtension($ext);
            $stem = Files::stem($path);

            if ($runtime !== null) {
                $facet = Spec::pairedFacet($stem);
                if ($facet !== null) {
                    $pairs[$stem] ??= ['facet' => $facet['id'], 'node' => null, 'php' => null];
                    $pairs[$stem][$runtime] ??= $path;
                    continue;
                }
            }

            $shared = Spec::sharedFacet($path);
            if ($shared !== null) {
                $sharedCount++;
                $byFacet[$shared['id']] = ($byFacet[$shared['id']] ?? 0) + 1;
                continue;
            }

            $unsupportedFacet = Spec::unsupportedFacet($path);
            if ($unsupportedFacet !== null) {
                // report the top directory the glob names (src/plugins/<name>), once
                $prefixSegments = substr_count(explode('**', $unsupportedFacet['glob'])[0], '/');
                $dir = implode('/', array_slice(explode('/', $path), 0, $prefixSegments));
                if (!in_array($dir, $unsupported, true)) {
                    $unsupported[] = $dir;
                }
            }
        }

        $paired = [];
        ksort($pairs, SORT_STRING);
        foreach ($pairs as $stem => $pair) {
            $paired[] = [
                'facet' => $pair['facet'],
                'stem' => $stem,
                'node' => $pair['node'],
                'php' => $pair['php'],
                'state' => $pair['node'] === null ? 'missing-node' : ($pair['php'] === null ? 'missing-php' : 'paired'),
            ];
        }

        $missing = [];
        foreach (Spec::shared() as $facet) {
            if (($facet['required'] ?? false) && !isset($byFacet[$facet['id']])) {
                $missing[] = $facet['id'];
            }
        }
        ksort($byFacet, SORT_STRING);

        return [
            'paired' => $paired,
            'shared' => ['count' => $sharedCount, 'byFacet' => $byFacet, 'missing' => $missing],
            'unsupported' => $unsupported,
        ];
    }
}
