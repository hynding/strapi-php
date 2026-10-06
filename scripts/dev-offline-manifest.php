#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Writes composer.dev.json: the root manifest with Packagist disabled and every third-party
 * dependency resolved from its GitHub mirror. Used in sandboxes where repo.packagist.org
 * is unreachable but github.com is. Run Composer with COMPOSER=composer.dev.json.
 */
$root = dirname(__DIR__);
$manifest = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$mirrors = json_decode((string) file_get_contents($root . '/scripts/github-mirrors.json'), true, 512, JSON_THROW_ON_ERROR);
$manifest['repositories'] = array_merge(
    $manifest['repositories'],
    array_map(static fn (string $repo) => ['type' => 'git', 'url' => "https://github.com/{$repo}.git"], $mirrors),
    [['packagist.org' => false]],
);
file_put_contents($root . '/composer.dev.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "Wrote composer.dev.json with " . count($mirrors) . " GitHub mirrors. Use: COMPOSER=composer.dev.json composer update\n";
