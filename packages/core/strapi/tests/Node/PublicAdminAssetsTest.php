<?php

declare(strict_types=1);

namespace Strapi\Cli\Tests\Node;

use PHPUnit\Framework\TestCase;
use Strapi\Cli\Cli\Utils\Logger;
use Strapi\Cli\Node\Core\PublicAdminAssets;

final class PublicAdminAssetsTest extends TestCase
{
    private string $root = '';

    /** @var resource */
    private $err;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/strapi-public-admin-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/build/sub', 0o755, true);
        mkdir($this->root . '/public');
        file_put_contents($this->root . '/build/index.html', '<html></html>');
        file_put_contents($this->root . '/build/index-Ab12Cd.js', 'js');
        file_put_contents($this->root . '/build/style-Ef34.css', 'css');
        file_put_contents($this->root . '/build/sub/font.woff2', 'font');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($err);
        $this->err = $err;
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function logger(): Logger
    {
        $out = fopen('php://memory', 'w+');
        self::assertIsResource($out);

        return new Logger(false, false, false, $out, $this->err);
    }

    private function stderr(): string
    {
        rewind($this->err);

        return (string) stream_get_contents($this->err);
    }

    public function testCopiesTheBundleWithoutTheShell(): void
    {
        $target = PublicAdminAssets::publish($this->root . '/build', $this->root . '/public', '/admin', $this->logger());

        self::assertSame($this->root . '/public/admin', $target);
        self::assertSame('js', file_get_contents($target . '/index-Ab12Cd.js'));
        self::assertSame('css', file_get_contents($target . '/style-Ef34.css'));
        self::assertSame('font', file_get_contents($target . '/sub/font.woff2'));
        self::assertFileDoesNotExist($target . '/index.html', 'the shell and the SPA routes go to index.php');
        self::assertFileDoesNotExist($target . '/index.php', '/admin/ goes to the front controller (or its worker)');
        $htaccess = (string) file_get_contents($target . '/.htaccess');
        self::assertStringStartsWith(PublicAdminAssets::MARKER, $htaccess);
        self::assertStringContainsString('Header set Cache-Control "public, max-age=31536000, immutable"', $htaccess);
        self::assertStringContainsString('Options -Indexes', $htaccess);
        self::assertTrue(PublicAdminAssets::isPublished($target));
        self::assertSame("*\n", file_get_contents($target . '/.gitignore'), 'git ignores the copy wherever admin.path puts it');
    }

    public function testRemovesWhatAKilledBuildLeft(): void
    {
        mkdir($this->root . '/public/.admin.new-123-abcdef');
        file_put_contents($this->root . '/public/.admin.new-123-abcdef/x.js', 'x');
        mkdir($this->root . '/public/.admin.old-123-abcdef');

        PublicAdminAssets::publish($this->root . '/build', $this->root . '/public', '/admin', $this->logger());

        self::assertSame(['admin'], array_values(array_diff(scandir($this->root . '/public') ?: [], ['.', '..'])));
    }

    public function testABuildReplacesTheEarlierCopy(): void
    {
        PublicAdminAssets::publish($this->root . '/build', $this->root . '/public', '/admin', $this->logger());
        unlink($this->root . '/build/index-Ab12Cd.js');
        file_put_contents($this->root . '/build/index-Zz99.js', 'new');

        PublicAdminAssets::publish($this->root . '/build', $this->root . '/public', '/admin/', $this->logger());

        self::assertFileDoesNotExist($this->root . '/public/admin/index-Ab12Cd.js');
        self::assertSame('new', file_get_contents($this->root . '/public/admin/index-Zz99.js'));
        self::assertSame(['admin'], array_values(array_diff(scandir($this->root . '/public') ?: [], ['.', '..'])), 'no staging or old copy left');
    }

    public function testANestedAdminPath(): void
    {
        $target = PublicAdminAssets::publish($this->root . '/build', $this->root . '/public', '/cms/dashboard', $this->logger());

        self::assertSame($this->root . '/public/cms/dashboard', $target);
        self::assertFileExists($target . '/index-Ab12Cd.js');
    }

    public function testNeverTouchesADirectoryOfTheProject(): void
    {
        mkdir($this->root . '/public/admin');
        file_put_contents($this->root . '/public/admin/mine.txt', 'mine');

        self::assertNull(PublicAdminAssets::publish($this->root . '/build', $this->root . '/public', '/admin', $this->logger()));
        self::assertSame('mine', file_get_contents($this->root . '/public/admin/mine.txt'));
        self::assertFileDoesNotExist($this->root . '/public/admin/index-Ab12Cd.js');
        self::assertStringContainsString('was not written by `strapi build`', $this->stderr());
    }

    public function testNothingForAnAdminAtTheRootOrOutsidePublic(): void
    {
        self::assertNull(PublicAdminAssets::publish($this->root . '/build', $this->root . '/public', '/', $this->logger()));
        self::assertNull(PublicAdminAssets::publish($this->root . '/build', $this->root . '/public', '', $this->logger()));
        self::assertNull(PublicAdminAssets::publish($this->root . '/build', $this->root . '/public', '/../admin', $this->logger()));
        self::assertNull(PublicAdminAssets::publish($this->root . '/missing', $this->root . '/public', '/admin', $this->logger()));
        self::assertSame(['.', '..'], scandir($this->root . '/public'));
    }
}
