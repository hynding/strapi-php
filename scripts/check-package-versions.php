#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Ensures every workspace composer.json that has a "version" field uses the same version
 * as the canonical source (packages/core/strapi/composer.json). Mirrors upstream's
 * scripts/check-package-versions.mjs.
 *
 * Usage:
 *   php scripts/check-package-versions.php          # check only, exit 1 on mismatch
 *   php scripts/check-package-versions.php --fix    # write the canonical version everywhere
 *   php scripts/check-package-versions.php --set 5.57.0-beta.1   # set a new canonical version, then --fix
 */

$root = dirname(__DIR__);
$canonicalPath = $root . '/packages/core/strapi/composer.json';
$args = array_slice($argv, 1);
$fix = in_array('--fix', $args, true);
$setIndex = array_search('--set', $args, true);
$newVersion = $setIndex !== false ? ($args[$setIndex + 1] ?? null) : null;

$readJson = static function (string $path): array {
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($data));

    return $data;
};

$writeJson = static function (string $path, array $data): void {
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
};

$canonical = $readJson($canonicalPath);

if ($newVersion !== null) {
    if (!preg_match('/^\d+\.\d+\.\d+(\.\d+)?(-(alpha|beta|RC)\.?\d+)?$/', $newVersion)) {
        fwrite(STDERR, "Invalid version: {$newVersion}\n");
        exit(2);
    }
    $canonical['version'] = $newVersion;
    $writeJson($canonicalPath, $canonical);
    $fix = true;
}

$version = $canonical['version'] ?? null;
if (!is_string($version)) {
    fwrite(STDERR, "Canonical package has no version: {$canonicalPath}\n");
    exit(2);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static fn (SplFileInfo $f): bool => !in_array($f->getFilename(), ['vendor', 'node_modules', '.git', 'dist'], true),
    ),
);

$mismatches = [];
$checked = 0;
foreach ($iterator as $file) {
    if ($file->getFilename() !== 'composer.json') {
        continue;
    }
    $path = $file->getPathname();
    $data = $readJson($path);
    if (!isset($data['version'])) {
        continue;
    }
    $checked++;
    if ($data['version'] === $version) {
        continue;
    }
    $mismatches[] = [$path, $data['version']];
    if ($fix) {
        $data['version'] = $version;
        $writeJson($path, $data);
    }
}

$rel = static fn (string $p): string => substr($p, strlen($root) + 1);
if ($mismatches === []) {
    echo "All {$checked} versioned packages are at {$version}.\n";
    exit(0);
}
foreach ($mismatches as [$path, $found]) {
    echo ($fix ? 'fixed   ' : 'MISMATCH') . "  {$rel($path)}: {$found} -> {$version}\n";
}
exit($fix ? 0 : 1);
