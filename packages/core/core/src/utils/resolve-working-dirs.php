<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

/**
 * Port of packages/core/core/src/utils/resolve-working-dirs.ts.
 *
 * - `appDir` is the directory where Strapi writes every file (schemas, generated APIs...)
 * - `distDir` is where it reads configurations and code; in PHP there is no compile step so it
 *   defaults to `appDir`.
 */
final class ResolveWorkingDirs
{
    /**
     * @param array{appDir?: string|null, distDir?: string|null} $opts
     * @return array{appDir: string, distDir: string}
     */
    public static function resolveWorkingDirectories(array $opts): array
    {
        $cwd = getcwd() ?: '.';

        $appDir = !empty($opts['appDir']) ? self::resolve($cwd, $opts['appDir']) : $cwd;
        $distDir = !empty($opts['distDir']) ? self::resolve($cwd, $opts['distDir']) : $appDir;

        return ['appDir' => $appDir, 'distDir' => $distDir];
    }

    private static function resolve(string $cwd, string $path): string
    {
        $full = str_starts_with($path, '/') ? $path : $cwd . '/' . $path;
        $real = realpath($full);

        return rtrim($real !== false ? $real : $full, '/');
    }
}
