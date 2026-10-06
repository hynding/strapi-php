<?php

declare(strict_types=1);

namespace Strapi\Core\Configuration;

use Strapi\Types\Core\StrapiDirectories;

/**
 * Port of packages/core/core/src/configuration/get-dirs.ts. Upstream distinguishes `dist` (compiled
 * output) and `app`; PHP has no compile step so both point at the project root.
 */
final class GetDirs
{
    /**
     * @param array{appDir: string, distDir?: string} $opts
     * @param array<string, mixed> $config
     */
    public static function getDirs(array $opts, array $config): StrapiDirectories
    {
        $appDir = rtrim($opts['appDir'], '/');
        $publicDir = $config['server']['dirs']['public'] ?? './public';

        return StrapiDirectories::fromRoot($appDir, self::resolve($appDir, (string) $publicDir));
    }

    public static function resolve(string $base, string $path): string
    {
        if (str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }

        $full = $base . '/' . $path;
        $parts = [];
        foreach (explode('/', $full) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return '/' . implode('/', $parts);
    }
}
