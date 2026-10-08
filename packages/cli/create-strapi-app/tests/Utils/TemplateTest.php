<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\CreateStrapiApp\Tests\ScopeFactory;
use Strapi\CreateStrapiApp\Utils\Template;

final class TemplateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/csa-tpl-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        Template::$fetch = null;
        Template::removeDirectory($this->dir);
    }

    /** A GitHub tarball: everything under one `owner-repo-sha/` directory. */
    private function tarball(): string
    {
        $src = $this->dir . '/src/acme-blog-abc123';
        mkdir($src . '/templates/blog/config', 0777, true);
        file_put_contents($src . '/README.md', 'root');
        file_put_contents($src . '/templates/blog/composer.json', '{}');
        file_put_contents($src . '/templates/blog/config/api.php', '<?php return [];');

        $tar = $this->dir . '/repo.tar';
        $phar = new \PharData($tar);
        $phar->buildFromDirectory($this->dir . '/src');
        $phar->compress(\Phar::GZ);

        return (string) file_get_contents($tar . '.gz');
    }

    public function testDownloadsAGithubShorthandSubPath(): void
    {
        $tarball = $this->tarball();
        $requests = [];
        Template::$fetch = static function (string $method, string $url) use ($tarball, &$requests): array {
            $requests[] = "{$method} {$url}";

            return ['status' => 200, 'body' => $method === 'GET' ? $tarball : ''];
        };

        $root = $this->dir . '/app';
        Template::copyTemplate(ScopeFactory::scope(['template' => 'acme/blog/templates/blog', 'templateBranch' => 'main']), $root);

        self::assertSame([
            'HEAD https://api.github.com/repos/acme/blog/contents/templates/blog?ref=main',
            'GET https://api.github.com/repos/acme/blog/tarball/main',
        ], $requests);
        self::assertFileExists($root . '/composer.json');
        self::assertFileExists($root . '/config/api.php');
        self::assertFileDoesNotExist($root . '/README.md');
    }

    public function testReportsAMissingRepository(): void
    {
        Template::$fetch = static fn (): array => ['status' => 404, 'body' => ''];

        $this->expectExceptionMessage('Could not find a template at https://github.com/acme/nope');
        Template::copyTemplate(ScopeFactory::scope(['template' => 'https://github.com/acme/nope']), $this->dir . '/app');
    }

    public function testRejectsANonTreeGithubUrl(): void
    {
        $this->expectExceptionMessage('Invalid GitHub template URL');
        Template::copyTemplate(ScopeFactory::scope(['template' => 'https://github.com/acme/blog/blob/main/x']), $this->dir . '/app');
    }
}
