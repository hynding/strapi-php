<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Utils;

/**
 * Port of packages/cli/create-strapi-app/src/utils/check-install-path.ts.
 */
final class CheckInstallPath
{
    /** Checks if an empty directory exists at rootPath; returns the absolute path. */
    public static function checkInstallPath(string $directory, Logger $logger): string
    {
        $rootPath = self::resolve($directory);

        if (file_exists($rootPath)) {
            if (!is_dir($rootPath)) {
                $logger->fatal("<fg=green>{$rootPath}</> is not a directory. Make sure to create a Strapi application in an empty directory.");
            }

            $files = array_values(array_diff(scandir($rootPath) ?: [], ['.', '..']));
            if (count($files) > 1) {
                $logger->fatal([
                    'You can only create a Strapi app in an empty directory',
                    "Make sure <fg=green>{$rootPath}</> is empty.",
                ]);
            }
        }

        return $rootPath;
    }

    /** `path.resolve(directory)`: absolute, normalised, without requiring the path to exist. */
    public static function resolve(string $directory, ?string $cwd = null): string
    {
        $path = str_starts_with($directory, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $directory) === 1
            ? $directory
            : rtrim($cwd ?? (string) getcwd(), '/\\') . '/' . $directory;

        $prefix = '';
        if (preg_match('#^([A-Za-z]:)[\\\\/]#', $path, $m) === 1) {
            $prefix = $m[1];
            $path = substr($path, 2);
        }

        $segments = [];
        foreach (preg_split('#[\\\\/]+#', $path) ?: [] as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $prefix . '/' . implode('/', $segments);
    }
}
