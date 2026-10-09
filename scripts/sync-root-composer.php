#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * strapi-php is published as ONE Composer package, `hynding/strapi-php` (this repository's root
 * composer.json), the way `laravel/framework` and `symfony/symfony` ship. Packagist reads one
 * composer.json per Git repository, so the packages under packages/ are not published one by one;
 * the root package carries them all:
 *
 * - `replace`  every bundled `strapi/*` package at `self.version`, so a project or a plugin that
 *              requires `strapi/core` (or `strapi/i18n`...) is satisfied by `hynding/strapi-php`;
 * - `require`  the union of the bundled packages' third-party requirements;
 * - `autoload` every bundled package's classmap, prefixed with its directory;
 * - `bin`      every bundled package's binaries.
 *
 * Those four keys are generated from packages/*\/*\/composer.json; everything else in the root
 * composer.json (name, require-dev, autoload-dev, scripts...) is hand-written.
 *
 * Usage:
 *   php scripts/sync-root-composer.php           # check only, exit 1 when out of sync
 *   php scripts/sync-root-composer.php --fix     # rewrite the generated keys
 */

$root = dirname(__DIR__);

/** packages that stay out of the bundle, and why */
const UNBUNDLED = [
    'packages/cli/create-strapi-app' => 'the project generator: run before a project exists, published on its own (split.yml)',
    'packages/cli/create-strapi' => 'alias of create-strapi-app (upstream parity), not published',
    'packages/utils/api-tests' => 'tests/api harness (autoload-dev)',
    'packages/utils/phpstan-config' => 'development configuration',
    'packages/core/content-releases' => 'Enterprise-licensed upstream, not ported (empty)',
    'packages/core/review-workflows' => 'Enterprise-licensed upstream, not ported (empty)',
];

$readJson = static function (string $path): array {
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    assert(is_array($data));

    return $data;
};

$rootPath = $root . '/composer.json';
$composer = $readJson($rootPath);

$require = ['php' => '>=8.3'];
$requiredBy = [];
$replace = [];
$classmap = [];
$bin = [];
$errors = [];

$files = glob($root . '/packages/*/*/composer.json') ?: [];
sort($files);

foreach ($files as $file) {
    $dir = substr(dirname($file), strlen($root) + 1);
    if (isset(UNBUNDLED[$dir])) {
        continue;
    }

    $package = $readJson($file);
    $name = $package['name'] ?? null;
    if (!is_string($name) || !str_starts_with($name, 'strapi/')) {
        $errors[] = "{$dir}: a bundled package must be named strapi/*";
        continue;
    }
    $replace[$name] = 'self.version';

    foreach ($package['require'] ?? [] as $dep => $constraint) {
        if (str_starts_with($dep, 'strapi/') || $dep === 'php') {
            continue;
        }
        if (isset($require[$dep]) && $require[$dep] !== $constraint) {
            $errors[] = "{$dep}: {$requiredBy[$dep]} requires {$require[$dep]} but {$name} requires {$constraint}; use one constraint";
            continue;
        }
        $require[$dep] = $constraint;
        $requiredBy[$dep] ??= $name;
    }

    $autoload = $package['autoload'] ?? [];
    foreach (array_diff(array_keys($autoload), ['classmap']) as $kind) {
        $errors[] = "{$dir}: autoload.{$kind} is not supported (packages autoload by classmap)";
    }
    foreach ($autoload['classmap'] ?? [] as $path) {
        $classmap[] = $dir . '/' . $path;
    }
    foreach ($package['bin'] ?? [] as $path) {
        $bin[] = $dir . '/' . $path;
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(2);
}

// Composer's sort-packages order: php, extensions, packages
uksort($require, static function (string $a, string $b): int {
    $rank = static fn (string $n): int => $n === 'php' ? 0 : (str_starts_with($n, 'ext-') ? 1 : 2);

    return [$rank($a), $a] <=> [$rank($b), $b];
});
ksort($replace);

$generated = [
    'require' => $require,
    'replace' => $replace,
    'autoload' => ['classmap' => $classmap],
    'bin' => $bin,
];

$outOfSync = [];
foreach ($generated as $key => $value) {
    if (($composer[$key] ?? null) !== $value) {
        $outOfSync[] = $key;
    }
}

if ($outOfSync === []) {
    echo 'composer.json is in sync with ' . count($replace) . " bundled packages\n";
    exit(0);
}

if (!in_array('--fix', $argv, true)) {
    fwrite(STDERR, 'composer.json is out of sync (' . implode(', ', $outOfSync) . "): run `composer bundle:fix`\n");
    exit(1);
}

// keep the hand-written keys where they are; the generated ones go after `license`
$order = ['name', 'description', 'type', 'keywords', 'homepage', 'license', 'version', 'require', 'require-dev', 'replace', 'autoload', 'autoload-dev', 'bin'];
$result = [];
foreach ($order as $key) {
    if (array_key_exists($key, $generated)) {
        $result[$key] = $generated[$key];
    } elseif (array_key_exists($key, $composer)) {
        $result[$key] = $composer[$key];
    }
}
$result += $composer;

file_put_contents($rootPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo 'composer.json updated (' . implode(', ', $outOfSync) . ")\n";
