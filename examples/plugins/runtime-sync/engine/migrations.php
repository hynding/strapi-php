<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Engine;

/**
 * Mirrors engine/migrations.js: the `strapi_migrations` log names one migration per runtime
 * (`2024.01.01T00.00.00.add.js` on Node, `….php` on strapi-php). On a shared database the twin
 * that already ran must not run again, so the server half logs the twin's name before the
 * runtime's own migration runner looks (`twinsToLog`), and `pending` shows what will still run.
 * `.sql` migrations are one file for both runtimes.
 */
final class Migrations
{
    public const RUNTIME_EXTENSIONS = ['php', 'js', 'ts', 'mjs', 'cjs'];

    /** `name.js` → `name.`; null for `.sql` and anything that is not a runtime file */
    public static function stem(string $name): ?string
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        if (!in_array($ext, self::RUNTIME_EXTENSIONS, true)) {
            return null;
        }

        return substr($name, 0, -strlen($ext));
    }

    /**
     * The names to add to the log: for every executed migration, the twins present in the
     * migrations directory that are not logged themselves.
     *
     * @param list<string> $executed logged names
     * @param list<string> $files file names in database/migrations
     * @return list<string>
     */
    public static function twinsToLog(array $executed, array $files): array
    {
        $logged = array_flip($executed);
        $out = [];
        foreach ($executed as $name) {
            $stem = self::stem($name);
            if ($stem === null) {
                continue;
            }
            foreach ($files as $file) {
                if ($file !== $name && self::stem($file) === $stem && !isset($logged[$file]) && !in_array($file, $out, true)) {
                    $out[] = $file;
                }
            }
        }
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * The migration files that would run: not logged under any runtime's spelling.
     *
     * @param list<string> $executed
     * @param list<string> $files
     * @return list<string>
     */
    public static function pending(array $executed, array $files): array
    {
        $logged = array_flip($executed);
        $loggedStems = [];
        foreach ($executed as $name) {
            $stem = self::stem($name);
            if ($stem !== null) {
                $loggedStems[$stem] = true;
            }
        }

        $out = [];
        foreach ($files as $file) {
            if (isset($logged[$file])) {
                continue;
            }
            $stem = self::stem($file);
            if ($stem !== null && isset($loggedStems[$stem])) {
                continue;
            }
            $out[] = $file;
        }
        sort($out, SORT_STRING);

        return $out;
    }

    /**
     * The migration files a runtime discovers: `.sql` plus its own extensions, sorted, no dot files.
     *
     * @param list<string> $files
     * @param 'node'|'php' $runtime
     * @return list<string>
     */
    public static function discoverable(array $files, string $runtime): array
    {
        $extensions = $runtime === 'php' ? ['php', 'sql'] : ['js', 'sql'];
        $out = array_values(array_filter($files, static fn (string $f): bool => !str_starts_with($f, '.') && in_array(pathinfo($f, PATHINFO_EXTENSION), $extensions, true)));
        sort($out, SORT_STRING);

        return $out;
    }
}
