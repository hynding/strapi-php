<?php

// Stands in for `npx @strapi/upgrade` in tests: `codemods run <uid> --project-path <dir>` appends
// a marker to every .tsx file under <dir>/src; a uid containing "-fail-" exits with 1.
$args = array_slice($argv, 1);
$uid = $args[2] ?? '';
$dir = $args[array_search('--project-path', $args, true) + 1] ?? '.';

if (str_contains($uid, '-fail-')) {
    fwrite(STDERR, "Unknown codemod UID provided: {$uid}\n");
    exit(1);
}

$package = json_decode((string) file_get_contents("{$dir}/package.json"), true);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$dir}/src", FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (str_ends_with((string) $file, '.tsx')) {
        file_put_contents((string) $file, "// {$uid} @strapi/strapi@{$package['dependencies']['@strapi/strapi']}\n", FILE_APPEND);
    }
}
