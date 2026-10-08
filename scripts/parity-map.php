#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Builds parity.json: every upstream server-side source file, its git blob hash at the
 * tracked upstream version, and whether this repository has the mirrored PHP file.
 *
 * Requires a checkout of strapi/strapi. Point to it with --upstream=<path> (default
 * ../strapi). The upstream tag is taken from packages/core/strapi/composer.json unless
 * --tag=vX.Y.Z is given. With --diff=<old parity.json> the script also lists files whose
 * upstream blob changed since that file was produced (the "changed-upstream" list the
 * weekly tracking issue needs).
 *
 * Usage:
 *   php scripts/parity-map.php --upstream=../strapi
 *   php scripts/parity-map.php --upstream=../strapi --tag=v5.57.0 --diff=parity.json --out=parity.next.json
 */

$root = dirname(__DIR__);
$opts = getopt('', ['upstream::', 'tag::', 'diff::', 'out::', 'summary']);
$upstream = realpath((string) ($opts['upstream'] ?? $root . '/../strapi')) ?: '';
if ($upstream === '' || !is_dir($upstream . '/packages')) {
    fwrite(STDERR, "Upstream checkout not found. Pass --upstream=<path to strapi/strapi>.\n");
    exit(2);
}
$canonical = json_decode((string) file_get_contents($root . '/packages/core/strapi/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$tracked = preg_replace('/^(\d+\.\d+\.\d+).*$/', '$1', (string) $canonical['version']);
$tag = (string) ($opts['tag'] ?? 'v' . $tracked);
$out = (string) ($opts['out'] ?? $root . '/parity.json');

// Server-side files only: skip admin UIs, tests, type declarations, build output.
$cmd = sprintf('git -C %s ls-tree -r %s -- packages 2>/dev/null', escapeshellarg($upstream), escapeshellarg($tag));
exec($cmd, $lines, $code);
if ($code !== 0 || $lines === []) {
    // Shallow clones may not have the tag; fall back to HEAD and say so.
    exec(sprintf('git -C %s ls-tree -r HEAD -- packages', escapeshellarg($upstream)), $lines, $code);
    $sha = trim((string) shell_exec(sprintf('git -C %s rev-parse --short HEAD', escapeshellarg($upstream))));
    $tag = 'HEAD@' . $sha;
}

$isServerFile = static function (string $path): bool {
    if (!preg_match('/\.(ts|js|json)$/', $path)) {
        return false;
    }
    // An `admin/` UI folder sits right under a package root (packages/<group>/<name>/admin/...), and
    // packages/core/admin keeps its UI in admin/ and its server in server/ and ee/server/. Match the
    // folder after the package root only, so the admin package's server half is not dropped.
    $rest = implode('/', array_slice(explode('/', $path), 3));
    if (preg_match('#(^|/)(admin|ee/admin)/#', $rest) || preg_match('#/(__tests__|dist|node_modules|__mocks__)/#', $path)) {
        return false;
    }
    if (preg_match('/\.(test|spec)\.[tj]sx?$|\.d\.ts$|\.(config|setup)\.[mc]?js$|package\.json$|tsconfig.*\.json$|rollup\.config|lint-staged|jest\.config/', $path)) {
        return false;
    }
    // Only packages we mirror: everything under packages/* except cli/cloud and plugins/cloud
    if (preg_match('#^packages/(cli/cloud|plugins/cloud|admin-test-utils|utils/(eslint-config-custom|oxlint-config|tsconfig|vitest-config|typescript))/#', $path)) {
        return false;
    }
    return (bool) preg_match('#^packages/(core|plugins|providers|utils|generators|cli)/#', $path);
};

$phpPathFor = static function (string $upstreamPath): string {
    $p = preg_replace('/\.(ts|js)$/', '.php', $upstreamPath) ?? $upstreamPath;
    // upstream packages/utils/typescript → ours packages/utils/type-utils (not mirrored yet)
    return $p;
};

$entries = [];
foreach ($lines as $line) {
    // <mode> blob <hash>\t<path>
    if (!preg_match('/^\d+ blob ([0-9a-f]+)\t(.+)$/', $line, $m)) {
        continue;
    }
    [, $hash, $path] = $m;
    if (!$isServerFile($path)) {
        continue;
    }
    $php = $phpPathFor($path);
    $entries[$path] = [
        'upstream' => $hash,
        'php' => $php,
        'ported' => file_exists($root . '/' . $php),
    ];
    // Enterprise Edition code (packages/*/*/ee/...) is under Strapi's EE licence, not MIT: tracked,
    // but blocked until the licence is confirmed (see VERSIONING.md / the package READMEs).
    if (preg_match('#^packages/[^/]+/[^/]+/(.*/)?ee/#', $path) === 1) {
        $entries[$path]['ee'] = true;
    }
}
ksort($entries);

$changed = [];
if (isset($opts['diff']) && is_file((string) $opts['diff'])) {
    $old = json_decode((string) file_get_contents((string) $opts['diff']), true, 512, JSON_THROW_ON_ERROR);
    $oldFiles = $old['files'] ?? [];
    foreach ($entries as $path => $e) {
        if (!isset($oldFiles[$path])) {
            $changed[$path] = 'added-upstream';
        } elseif ($oldFiles[$path]['upstream'] !== $e['upstream']) {
            $changed[$path] = 'changed-upstream';
        }
    }
    foreach (array_keys($oldFiles) as $path) {
        if (!isset($entries[$path])) {
            $changed[$path] = 'removed-upstream';
        }
    }
}

$ported = count(array_filter($entries, static fn ($e) => $e['ported']));
$ee = count(array_filter($entries, static fn ($e) => $e['ee'] ?? false));
$result = [
    'upstreamTag' => $tag,
    'generatedAt' => gmdate('c'),
    'totals' => ['files' => count($entries), 'ported' => $ported, 'ee' => $ee],
    'changed' => $changed,
    'files' => $entries,
];
file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

// Per-package summary for humans and the tracking issue.
$byPackage = [];
foreach ($entries as $path => $e) {
    $pkg = implode('/', array_slice(explode('/', $path), 0, 3));
    $byPackage[$pkg]['files'] = ($byPackage[$pkg]['files'] ?? 0) + 1;
    $byPackage[$pkg]['ported'] = ($byPackage[$pkg]['ported'] ?? 0) + ($e['ported'] ? 1 : 0);
}
printf("Upstream %s: %d server files (%d EE, blocked), %d ported (%.1f%%). Written to %s\n", $tag, count($entries), $ee, $ported, $ported * 100 / max(1, count($entries)), $out);
foreach ($byPackage as $pkg => $c) {
    printf("  %-48s %4d / %4d\n", $pkg, $c['ported'], $c['files']);
}
if ($changed !== []) {
    printf("\n%d files changed upstream since the previous parity map:\n", count($changed));
    foreach ($changed as $path => $kind) {
        printf("  %-18s %s\n", $kind, $path);
    }
}
