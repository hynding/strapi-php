<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests;

use PHPUnit\Framework\Attributes\After;

/** A temporary directory per test, removed afterwards. */
trait TempDir
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/strapi-dts-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    #[After]
    protected function removeTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::rmrf($dir);
        }
        $this->tempDirs = [];
    }

    private static function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmrf("{$path}/{$entry}");
            }
        }
        rmdir($path);
    }
}
