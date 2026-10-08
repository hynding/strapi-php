<?php

declare(strict_types=1);

namespace Strapi\Generators\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A scratch project directory per test (upstream: `mkdtemp` + `process.cwd` spy), removed after.
 *
 * Loaded with `require_once` (the package's autoload-dev is not part of the root autoloader).
 */
abstract class GeneratorsTestCase extends TestCase
{
    protected string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = sys_get_temp_dir() . '/strapi-generators-' . bin2hex(random_bytes(6));
        mkdir($this->outputDirectory, 0o777, true);
    }

    protected function tearDown(): void
    {
        self::remove($this->outputDirectory);
    }

    protected static function remove(string $path): void
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
                self::remove("{$path}/{$entry}");
            }
        }
        rmdir($path);
    }

    /** fs-extra `outputFile` */
    protected static function outputFile(string $path, string $contents): void
    {
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);
    }

    /** fs-extra `outputJSON(path, value, { spaces: 2 })` */
    protected static function outputJSON(string $path, mixed $value): void
    {
        self::outputFile($path, \Strapi\Generators\Plops\Utils\Files::stringify($value) . "\n");
    }

    /** fs-extra `readJSON` (objects as arrays) */
    protected static function readJSON(string $path): mixed
    {
        return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    }

    protected static function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, "{$path} is readable");

        return $contents;
    }

    /**
     * Every file under a directory, relative, sorted.
     *
     * @return list<string>
     */
    protected static function files(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = substr($file->getPathname(), strlen($dir) + 1);
            }
        }
        sort($files);

        return $files;
    }

    /** `php -l` on a generated file. */
    protected static function assertValidPhp(string $path): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
        self::assertSame(0, $code, implode("\n", $output));
    }
}
