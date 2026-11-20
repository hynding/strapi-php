<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Utils\BootCache;

/** PHP port: the production boot cache (`.strapi/cache`), see Strapi\Core\Utils\BootCache. */
final class BootCacheTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/strapi-boot-cache-' . bin2hex(random_bytes(6)) . '/.strapi/cache';
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        @rmdir(dirname($this->dir));
        @rmdir(dirname($this->dir, 2));
    }

    /** @return list<string> */
    private function files(): array
    {
        return array_map('basename', glob($this->dir . '/*') ?: []);
    }

    public function testAnEntryIsReadBackUnderItsKey(): void
    {
        $cache = new BootCache($this->dir);
        $data = ['metas' => ['api::a.a' => ['tableName' => 'as', 'default' => null, 'n' => 1.5, 'ok' => true]], 'list' => [1, 2]];

        self::assertNull($cache->get('db-metadata', 'key-1'));
        self::assertTrue($cache->set('db-metadata', 'key-1', $data));

        self::assertSame($data, $cache->get('db-metadata', 'key-1'));
        self::assertNull($cache->get('db-metadata', 'key-2'));
        self::assertNull($cache->get('routes', 'key-1'));
    }

    public function testTheKeyIsInTheFileNameAndANewKeyReplacesTheEntrysOtherFiles(): void
    {
        // opcache.validate_timestamps=0 keeps serving a file rewritten in place: a new key is a new file
        $cache = new BootCache($this->dir);
        $cache->set('db-metadata', 'key-1', ['v' => 1]);
        $cache->set('routes', 'key-1', ['v' => 'r']);
        $first = $this->files();
        self::assertCount(2, $first);
        self::assertMatchesRegularExpression('~^db-metadata\.[0-9a-f]{16}\.php$~', $first[0]);

        $cache->set('db-metadata', 'key-2', ['v' => 2]);

        $files = $this->files();
        self::assertCount(2, $files);
        self::assertNotContains($first[0], $files, 'the entry for the old key is removed');
        self::assertSame(['v' => 2], $cache->get('db-metadata', 'key-2'));
        self::assertNull($cache->get('db-metadata', 'key-1'));
        self::assertSame(['v' => 'r'], $cache->get('routes', 'key-1'), 'other entries stay');
    }

    public function testOnlyPlainDataIsWritten(): void
    {
        $cache = new BootCache($this->dir);

        self::assertFalse($cache->set('db-metadata', 'k', ['default' => static fn (): int => 1]));
        self::assertFalse($cache->set('db-metadata', 'k', ['default' => new \stdClass()]));
        self::assertFalse($cache->set('db-metadata', 'k', ['n' => NAN]));
        self::assertSame([], $this->files());

        self::assertTrue(BootCache::isPlainData(['a' => ['b' => [null, 'x', 1, 2.5, false]]]));
    }

    public function testADirectoryThatCannotBeWrittenLeavesTheCacheEmpty(): void
    {
        $file = sys_get_temp_dir() . '/strapi-boot-cache-file-' . bin2hex(random_bytes(6));
        touch($file);

        try {
            // a regular file where the directory would be: mkdir() fails as on a read-only root
            $cache = new BootCache($file . '/cache');
            self::assertFalse($cache->set('routes', 'k', ['v' => 1]));
            self::assertNull($cache->get('routes', 'k'));
        } finally {
            unlink($file);
        }
    }

    public function testAnUnreadableEntryIsAMiss(): void
    {
        $cache = new BootCache($this->dir);
        $cache->set('routes', 'k', ['v' => 1]);
        $files = glob($this->dir . '/routes.*.php') ?: [];
        self::assertCount(1, $files);

        // truncated mid-write by something else than set() (which renames a complete file)
        file_put_contents($files[0], "<?php\n\nreturn [");
        self::assertNull($cache->get('routes', 'k'));

        file_put_contents($files[0], "<?php\n\nreturn 'not an array';\n");
        self::assertNull($cache->get('routes', 'k'));
    }

    public function testSupportedNeedsOpcacheInSharedMemory(): void
    {
        $enabled = function_exists('opcache_get_status') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)
            && filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL)
            && !filter_var(ini_get('opcache.file_cache_only'), FILTER_VALIDATE_BOOL);

        self::assertSame($enabled, BootCache::supported());
    }
}
