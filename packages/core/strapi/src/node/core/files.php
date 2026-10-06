<?php

declare(strict_types=1);

namespace Strapi\Cli\Node\Core;

/** Port of packages/core/strapi/src/node/core/files.ts (the parts without esbuild). */
final class Files
{
    public static function pathExists(string $path): bool
    {
        return file_exists($path);
    }

    /** Converts a system path to a module path (`\` → `/` on Windows). */
    public static function convertSystemPathToModulePath(string $sysPath): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? str_replace('\\', '/', $sysPath) : $sysPath;
    }

    /** Converts a module path to a system path. */
    public static function convertModulePathToSystemPath(string $modulePath): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? str_replace('/', '\\', $modulePath) : $modulePath;
    }

    /** `path.relative(from, to)` */
    public static function relative(string $from, string $to): string
    {
        $from = array_values(array_filter(explode('/', str_replace('\\', '/', rtrim($from, '/'))), 'strlen'));
        $to = array_values(array_filter(explode('/', str_replace('\\', '/', rtrim($to, '/'))), 'strlen'));

        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return implode('/', [...array_fill(0, count($from), '..'), ...$to]);
    }
}
