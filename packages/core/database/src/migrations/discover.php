<?php

declare(strict_types=1);

namespace Strapi\Database\Migrations;

/**
 * Port of packages/core/database/src/migrations/discover.ts: non-recursive `*.php` / `*.sql`
 * (`*.js` upstream) discovery in alphabetical order, skipping dot files.
 */
final class Discover
{
    /** @return list<string> absolute paths */
    public static function discoverMigrationFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            if (!str_ends_with($entry, '.php') && !str_ends_with($entry, '.sql')) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path)) {
                continue;
            }
            $files[] = $path;
        }

        sort($files, SORT_STRING);

        return $files;
    }
}
