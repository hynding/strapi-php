<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Strapi\Core\Strapi;

/** Port of packages/core/core/src/services/fs.ts: `strapi.fs`. */
final class Fs
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public static function createStrapiFs(Strapi $strapi): self
    {
        return new self($strapi);
    }

    /** @param string|list<string> $optPath */
    private function normalizePath(string|array $optPath): string
    {
        $filePath = is_array($optPath) ? implode('/', $optPath) : $optPath;

        // posix.normalize + strip leading ./ and ../
        $segments = [];
        foreach (explode('/', $filePath) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $this->strapi->dirs()->root . '/' . implode('/', $segments);
    }

    /**
     * Writes a file in a strapi app.
     *
     * @param string|list<string> $optPath
     */
    public function writeAppFile(string|array $optPath, string $data): void
    {
        $writePath = $this->normalizePath($optPath);
        $dir = dirname($writePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        file_put_contents($writePath, $data);
    }

    /**
     * Writes a file in a plugin extensions folder.
     *
     * @param string|list<string> $optPath
     */
    public function writePluginFile(string $plugin, string|array $optPath, string $data): void
    {
        $this->writeAppFile(['extensions', $plugin, ...(is_array($optPath) ? $optPath : [$optPath])], $data);
    }

    /** @param string|list<string> $optPath */
    public function removeAppFile(string|array $optPath): void
    {
        $removePath = $this->normalizePath($optPath);
        if (is_file($removePath)) {
            unlink($removePath);
        } elseif (is_dir($removePath)) {
            self::removeDir($removePath);
        }
    }

    /** @param string|list<string> $optPath */
    public function appendFile(string|array $optPath, string $data): void
    {
        file_put_contents($this->normalizePath($optPath), $data, FILE_APPEND);
    }

    private static function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
