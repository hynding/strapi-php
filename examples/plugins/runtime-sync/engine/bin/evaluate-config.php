#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Parses a strapi-php config file (`config/*.php`) into the IR and prints it as JSON:
 * `php evaluate-config.php <projectRoot> <relativePath>`. The Node CLI runs it to read PHP
 * configs (engine/evaluate-config.js); the PHP CLI calls EvaluateConfig::evaluatePhp() directly.
 *
 * Needs an autoloader with nikic/php-parser and this plugin's engine: the project's vendor, or
 * the one this plugin is installed in (vendor/strapi-plugin/runtime-sync → vendor/autoload.php),
 * or the strapi-php monorepo's.
 */

[$root, $relPath] = [$argv[1] ?? null, $argv[2] ?? null];
if ($root === null || $relPath === null) {
    fwrite(STDERR, "usage: evaluate-config.php <projectRoot> <relativePath>\n");
    exit(2);
}

foreach ([
    $root . '/vendor/autoload.php',
    __DIR__ . '/../../../../autoload.php',
    __DIR__ . '/../../../../../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}
if (!class_exists(\StrapiPlugin\RuntimeSync\Engine\EvaluateConfig::class)) {
    foreach (glob(__DIR__ . '/../*.php') ?: [] as $file) {
        require_once $file;
    }
}
if (!class_exists(\PhpParser\ParserFactory::class)) {
    fwrite(STDERR, "nikic/php-parser is not installed: run `composer install` in the project\n");
    exit(1);
}

try {
    $doc = \StrapiPlugin\RuntimeSync\Engine\EvaluateConfig::evaluatePhp($root, $relPath);
    echo \StrapiPlugin\RuntimeSync\Engine\Ir::encode($doc);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
