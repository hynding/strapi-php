<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Middlewares;

require_once __DIR__ . '/../BootedAdminApp.php';

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Strapi\Admin\Middlewares\RateLimit;
use Strapi\Admin\Middlewares\RateLimitStores\FileStore;
use Strapi\Admin\Middlewares\RateLimitStores\MemoryStore;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/**
 * koa2-ratelimit's store, and the PHP port's choice of it: memory for long-lived processes, files
 * where every request starts afresh (PHP-FPM, CGI, mod_php, the built-in server).
 */
final class RateLimitStoreTest extends TestCase
{
    private static ?Strapi $strapi = null;

    private string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$strapi = BootedAdminApp::boot();
    }

    public static function tearDownAfterClass(): void
    {
        self::$strapi?->destroy();
        self::$strapi = null;
    }

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/strapi-rate-limit-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        array_map('unlink', [...(glob($this->dir . '/*/*') ?: []), ...(glob($this->dir . '/.sweep.lock') ?: [])]);
        array_map('rmdir', glob($this->dir . '/*', GLOB_ONLYDIR) ?: []);
        @rmdir($this->dir);
    }

    /** @return list<string> */
    private function files(): array
    {
        return glob($this->dir . '/*/*.json') ?: [];
    }

    private static function strapi(): Strapi
    {
        return self::$strapi ?? throw new \LogicException('Strapi is not booted');
    }

    private static function ctx(): Context
    {
        return new Context(new ServerRequest('POST', 'http://localhost/admin/login', [], null, '1.1', ['REMOTE_ADDR' => '203.0.113.7']));
    }

    public function testTheFileStoreCountsWithinAWindowAndStartsANewOneAfterIt(): void
    {
        $store = new FileStore($this->dir);

        self::assertSame(['counter' => 1, 'dateEnd' => 61_000], $store->incr('k', 60_000, 1_000));
        self::assertSame(['counter' => 2, 'dateEnd' => 61_000], $store->incr('k', 60_000, 30_000));
        self::assertSame(['counter' => 1, 'dateEnd' => 60_000], $store->incr('other', 59_000, 1_000));
        // the window ended: a new one starts
        self::assertSame(['counter' => 1, 'dateEnd' => 121_000], $store->incr('k', 60_000, 61_000));
    }

    public function testTheFileStoreRemembersAcrossInstancesAsAcrossRequests(): void
    {
        (new FileStore($this->dir))->incr('k', 60_000, 1_000);
        (new FileStore($this->dir))->incr('k', 60_000, 2_000);

        self::assertSame(3, (new FileStore($this->dir))->incr('k', 60_000, 3_000)['counter']);
    }

    public function testTheFileStoreRemovesTheFilesOfEndedWindows(): void
    {
        $store = new FileStore($this->dir);
        $nowMs = (int) floor(microtime(true) * 1000);
        $store->incr('ended', 1_000, $nowMs - 10_000);
        $store->incr('current', 60_000, $nowMs);

        $store->removeExpired($nowMs);

        self::assertCount(1, $this->files());
        self::assertSame(2, $store->incr('current', 60_000, $nowMs)['counter']);
    }

    public function testTheFileStoreSpreadsKeysOverSubdirectoriesAndSweepsOneAtATime(): void
    {
        $store = new FileStore($this->dir);
        $nowMs = (int) floor(microtime(true) * 1000);
        $store->incr('ended', 1_000, $nowMs - 10_000);
        $file = $this->files()[0];
        $shard = basename(dirname($file));
        self::assertSame(substr(hash('sha256', 'ended'), 0, 2), $shard);

        $store->removeExpired($nowMs, $shard === '00' ? '01' : '00');
        self::assertFileExists($file, 'another subdirectory');

        $store->removeExpired($nowMs, $shard);
        self::assertFileDoesNotExist($file);
    }

    public function testASweepLeavesAFileThatIsBeingCountedIn(): void
    {
        $store = new FileStore($this->dir);
        $nowMs = (int) floor(microtime(true) * 1000);
        $store->incr('k', 1_000, $nowMs - 10_000);
        $file = $this->files()[0];

        // another request holds the file: it may be starting a new window in it
        $handle = fopen($file, 'r');
        self::assertNotFalse($handle);
        flock($handle, LOCK_EX);
        try {
            $store->removeExpired($nowMs);
            self::assertFileExists($file);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function testTheFileStoreFailsOpenWhenItCannotWrite(): void
    {
        // a directory that cannot be created: under a file
        touch($this->dir);
        try {
            $store = new FileStore($this->dir . '/rate-limit');
            foreach ([1_000, 2_000, 3_000] as $nowMs) {
                self::assertSame(['counter' => 1, 'dateEnd' => $nowMs + 60_000], $store->incr('k', 60_000, $nowMs));
            }
            self::assertFalse((new \ReflectionMethod(RateLimit::class, 'isWritableDirectory'))->invoke(null, $this->dir . '/rate-limit'));
            self::assertTrue((new \ReflectionMethod(RateLimit::class, 'isWritableDirectory'))->invoke(null, sys_get_temp_dir()));
        } finally {
            unlink($this->dir);
        }
    }

    public function testTheMemoryStoreCountsWithinAWindow(): void
    {
        $store = new MemoryStore();

        self::assertSame(1, $store->incr('k', 60_000, 1_000)['counter']);
        self::assertSame(2, $store->incr('k', 60_000, 2_000)['counter']);
        self::assertSame(1, $store->incr('k', 60_000, 61_000)['counter']);
    }

    public function testTheDefaultStoreFollowsTheSapi(): void
    {
        foreach (['fpm-fcgi', 'cgi-fcgi', 'apache2handler', 'litespeed', 'cli-server'] as $sapi) {
            self::assertInstanceOf(FileStore::class, RateLimit::defaultStore(self::strapi(), $sapi), $sapi);
        }
        foreach (['cli', 'frankenphp'] as $sapi) {
            self::assertInstanceOf(MemoryStore::class, RateLimit::defaultStore(self::strapi(), $sapi), $sapi);
        }
        self::assertInstanceOf(MemoryStore::class, RateLimit::defaultStore(null, 'fpm-fcgi'), 'no project to keep files in');
    }

    public function testTheMiddlewareLimitsAcrossRequestsWithAFileStore(): void
    {
        $options = ['max' => 2, 'interval' => ['min' => 5], 'prefixKey' => 'login'];
        $next = static fn (): string => 'next';

        // one middleware per request, as each PHP-FPM request builds its own
        foreach ([1, 2] as $request) {
            $ctx = self::ctx();
            self::assertSame('next', RateLimit::middleware([...$options, 'store' => new FileStore($this->dir)])($ctx, $next), "request {$request}");
        }

        $ctx = self::ctx();
        self::assertNull(RateLimit::middleware([...$options, 'store' => new FileStore($this->dir)])($ctx, $next));
        self::assertSame(429, $ctx->status());
        self::assertSame('0', $ctx->responseHeader('X-RateLimit-Remaining'));
    }
}
