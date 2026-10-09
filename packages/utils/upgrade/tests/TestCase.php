<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Strapi\Upgrade\Modules\Format\Chalk;
use Strapi\Upgrade\Modules\Logger\Logger;

/** Shared helpers: temporary directories built from nested arrays (upstream uses memfs volumes). */
abstract class TestCase extends BaseTestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Chalk::$enabled = false;
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            self::remove($dir);
        }
        $this->tmpDirs = [];
        parent::tearDown();
    }

    /**
     * Creates a temporary directory holding `$tree` (`['src' => ['a.ts' => '…']]`) and returns its
     * real path.
     *
     * @param array<string, mixed> $tree
     */
    protected function volume(array $tree = []): string
    {
        $dir = sys_get_temp_dir() . '/strapi-upgrade-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->tmpDirs[] = $dir;
        self::write($dir, $tree);

        return (string) realpath($dir);
    }

    /** @param array<string, mixed> $tree */
    protected static function write(string $dir, array $tree): void
    {
        foreach ($tree as $name => $content) {
            $path = $dir . '/' . $name;
            if (is_array($content)) {
                if (!is_dir($path)) {
                    mkdir($path, 0o777, true);
                }
                self::write($path, $content);
            } else {
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0o777, true);
                }
                file_put_contents($path, (string) $content);
            }
        }
    }

    protected static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    /** @return array{0: Logger, 1: resource, 2: resource} [logger, stdout, stderr] */
    protected static function memoryLogger(bool $debug = false, bool $silent = false): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);

        return [Logger::loggerFactory(['debug' => $debug, 'silent' => $silent], $out, $err), $out, $err];
    }

    /** @param resource $stream */
    protected static function read($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /** @return array<string, mixed> */
    protected static function readJson(string $path): array
    {
        $json = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($json);

        return $json;
    }

    /**
     * A strapi-php application: composer.json requiring hynding/strapi-php, package.json pinning the
     * upstream admin packages.
     *
     * @param array<string, string> $require
     * @param array<string, string> $dependencies
     * @param array<string, mixed> $extra more files
     * @return array<string, mixed>
     */
    protected static function appTree(string $phpVersion, array $require = [], array $dependencies = [], array $extra = [], ?string $npmVersion = null): array
    {
        $npmVersion ??= preg_replace('/^(\d+\.\d+\.\d+).*$/', '$1', $phpVersion);

        return [
            'composer.json' => json_encode(['name' => 'acme/app', 'require' => ['php' => '>=8.3', 'hynding/strapi-php' => $phpVersion, ...$require]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            'package.json' => json_encode(['name' => 'app', 'version' => '0.1.0', 'dependencies' => ['@strapi/admin' => $npmVersion, '@strapi/strapi' => $npmVersion, ...$dependencies]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            ...$extra,
        ];
    }
}
