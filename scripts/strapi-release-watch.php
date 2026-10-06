#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Polls the npm registry for the latest @strapi/strapi version and compares it with the
 * version this repository tracks (packages/core/strapi/composer.json). Intended to run from
 * .github/workflows/release-watch.yml; prints GitHub Actions outputs when GITHUB_OUTPUT is set.
 *
 * Usage:
 *   php scripts/strapi-release-watch.php            # exit 0 and print "up to date" or the new version
 *   php scripts/strapi-release-watch.php --json     # machine-readable result
 *
 * Exit codes: 0 up to date, 10 new upstream version available, 2 error.
 */

$root = dirname(__DIR__);
$json = in_array('--json', array_slice($argv, 1), true);

$canonical = json_decode((string) file_get_contents($root . '/packages/core/strapi/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$ours = (string) ($canonical['version'] ?? '0.0.0');
// 5.57.0-beta.1 and 5.57.0.2 both track upstream 5.57.0
$tracked = preg_replace('/^(\d+\.\d+\.\d+).*$/', '$1', $ours) ?? $ours;

$ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "Accept: application/json\r\nUser-Agent: strapi-php-release-watch\r\n"]]);
$body = @file_get_contents('https://registry.npmjs.org/@strapi/strapi/latest', false, $ctx);
if ($body === false) {
    fwrite(STDERR, "Could not reach the npm registry.\n");
    exit(2);
}
$latest = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
$upstream = (string) ($latest['version'] ?? '');
if ($upstream === '') {
    fwrite(STDERR, "Registry response had no version.\n");
    exit(2);
}

$behind = version_compare($upstream, $tracked, '>');
$result = [
    'ours' => $ours,
    'tracked' => $tracked,
    'upstream' => $upstream,
    'behind' => $behind,
    'diffUrl' => "https://github.com/strapi/strapi/compare/v{$tracked}...v{$upstream}",
    'releaseUrl' => "https://github.com/strapi/strapi/releases/tag/v{$upstream}",
];

if (($out = getenv('GITHUB_OUTPUT')) !== false) {
    $lines = '';
    foreach ($result as $k => $v) {
        $lines .= $k . '=' . (is_bool($v) ? ($v ? 'true' : 'false') : $v) . "\n";
    }
    file_put_contents($out, $lines, FILE_APPEND);
}

if ($json) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} elseif ($behind) {
    echo "New upstream release: {$upstream} (we track {$tracked}, at {$ours}).\n{$result['diffUrl']}\n";
} else {
    echo "Up to date with upstream {$upstream}.\n";
}

exit($behind ? 10 : 0);
