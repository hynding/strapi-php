<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\File\Providers\Source;

/**
 * Port of src/file/providers/source/utils.ts.
 *
 * Note: in versions of the transfer engine <=4.9.0, exports were generated with windows paths
 * on Windows systems, and posix paths on posix systems. All paths are now stored as posix, but a
 * separator conversion stays for legacy archives and tar files created with Windows tools.
 */
final class Utils
{
    /**
     * @return array<string, mixed>
     */
    public static function validateAssetMetadata(mixed $metadata, ?string $filename = null): array
    {
        if (!is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) {
            throw new \TypeError('Asset sidecar metadata must be a JSON object');
        }

        $file = $metadata;
        $invalidFields = [];

        $id = $file['id'] ?? null;
        if (!(is_int($id) || (is_float($id) && floor($id) === $id && is_finite($id))) || $id <= 0) {
            $invalidFields[] = 'id';
        }
        $size = $file['size'] ?? null;
        if (!(is_int($size) || (is_float($size) && is_finite($size))) || $size < 0) {
            $invalidFields[] = 'size';
        }
        foreach (['name', 'hash', 'mime', 'url'] as $field) {
            if (!is_string($file[$field] ?? null) || $file[$field] === '') {
                $invalidFields[] = $field;
            }
        }
        foreach (['ext', 'type', 'mainHash'] as $field) {
            if (isset($file[$field]) && !is_string($file[$field])) {
                $invalidFields[] = $field;
            }
        }

        if ($invalidFields !== []) {
            throw new \TypeError('Asset sidecar metadata has invalid required fields: ' . implode(', ', $invalidFields));
        }

        $ext = $file['ext'] ?? null;
        $expectedFilename = $file['hash'] . ($ext ?? '');
        // `${hash}${ext}` in JS: an undefined/null extension is spelled out
        $legacyFilename = $file['hash'] . (array_key_exists('ext', $file) ? ($ext ?? 'null') : 'undefined');
        if ($filename !== null && $filename !== '' && $filename !== $expectedFilename && $filename !== $legacyFilename) {
            throw new \TypeError("Asset sidecar metadata does not match upload filename \"{$filename}\"");
        }

        return $file;
    }

    /**
     * Check if the directory of a given filePath (which can be either posix or win32) resolves to the same as the given posix-format path posixDirName
     */
    public static function isFilePathInDirname(string $posixDirName, string $filePath): bool
    {
        $normalizedDir = self::posixDirname(self::unknownPathToPosix($filePath));

        return self::isPathEquivalent($posixDirName, $normalizedDir);
    }

    /**
     * Check if two paths that can be either in posix or win32 format resolves to the same file
     */
    public static function isPathEquivalent(string $pathA, string $pathB): bool
    {
        // Check if paths appear to be win32 or posix, and if win32 convert to posix
        $normalizedPathA = rtrim(self::posixNormalize(self::unknownPathToPosix($pathA)), '/');
        $normalizedPathB = rtrim(self::posixNormalize(self::unknownPathToPosix($pathB)), '/');

        // path.posix.relative(b, a) is empty when both resolve to the same place
        return ($normalizedPathA === '' ? '.' : $normalizedPathA) === ($normalizedPathB === '' ? '.' : $normalizedPathB);
    }

    /**
     * Convert an unknown format path (win32 or posix) to a posix path
     */
    public static function unknownPathToPosix(string $filePath): string
    {
        // if it includes a forward slash, it must be posix already -- we will not support win32 with mixed path separators
        if (str_contains($filePath, '/')) {
            return $filePath;
        }

        return str_replace('\\', '/', self::posixNormalize($filePath));
    }

    /** node `path.posix.normalize()` */
    public static function posixNormalize(string $path): string
    {
        if ($path === '') {
            return '.';
        }

        $isAbsolute = $path[0] === '/';
        $trailingSeparator = str_ends_with($path, '/');

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments !== [] && end($segments) !== '..') {
                    array_pop($segments);
                } elseif (!$isAbsolute) {
                    $segments[] = '..';
                }
                continue;
            }
            $segments[] = $segment;
        }

        $normalized = implode('/', $segments);
        if ($normalized === '' && !$isAbsolute) {
            $normalized = '.';
        }
        if ($normalized !== '' && $trailingSeparator) {
            $normalized .= '/';
        }

        return ($isAbsolute ? '/' : '') . $normalized;
    }

    /** node `path.posix.dirname()` */
    public static function posixDirname(string $path): string
    {
        if ($path === '') {
            return '.';
        }
        $trimmed = rtrim($path, '/');
        if ($trimmed === '') {
            return '/';
        }
        $slash = strrpos($trimmed, '/');
        if ($slash === false) {
            return '.';
        }
        if ($slash === 0) {
            return '/';
        }

        return rtrim(substr($trimmed, 0, $slash), '/') ?: '/';
    }

    /** node `path.posix.basename()` */
    public static function posixBasename(string $path): string
    {
        $trimmed = rtrim($path, '/');
        $slash = strrpos($trimmed, '/');

        return $slash === false ? $trimmed : substr($trimmed, $slash + 1);
    }
}
