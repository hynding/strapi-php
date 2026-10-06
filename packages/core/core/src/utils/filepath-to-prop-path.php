<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

/** Port of packages/core/core/src/utils/filepath-to-prop-path.ts. */
final class FilepathToPropPath
{
    /**
     * Returns a path (as an array) from a file path.
     *
     * @return list<string>
     */
    public static function filePathToPropPath(string $entryPath, bool $useFileNameAsKey = true): array
    {
        $cleanPath = strtolower((string) preg_replace('/(\.settings|\.json|\.js|\.php)/', '', self::removeRelativePrefix($entryPath)));

        $parts = array_map(static fn (string $part): string => ltrim($part, '.'), preg_split('~[\\\\/]~', $cleanPath) ?: []);
        $parts = explode('.', implode('.', $parts));

        return $useFileNameAsKey ? $parts : array_slice($parts, 0, -1);
    }

    private static function removeRelativePrefix(string $filePath): string
    {
        return str_starts_with($filePath, './') || str_starts_with($filePath, '.\\') ? substr($filePath, 2) : $filePath;
    }
}
