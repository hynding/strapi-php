<?php

declare(strict_types=1);

namespace Strapi\Cli\Tests\Cli\Commands;

use PHPUnit\Framework\TestCase;
use Strapi\Cli\Cli\Commands\Bundle;
use Strapi\Cli\Node\Core\PublicAdminAssets;

final class BundleTest extends TestCase
{
    private string $root = '';

    private string $outside = '';

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/strapi-bundle-test-' . bin2hex(random_bytes(6));
        $this->root = $base . '/my-app';
        $this->outside = $base . '/outside';

        $files = [
            'package.json' => '{"name": "@acme/my-app"}',
            'composer.json' => '{}',
            '.env' => 'APP_KEYS=secret',
            '.env.production' => 'APP_KEYS=secret',
            '.env.example' => 'APP_KEYS=toBeModified',
            'bin/strapi' => '#!/usr/bin/env php',
            'config/server.php' => '<?php return [];',
            'src/index.php' => '<?php',
            'public/index.php' => '<?php',
            'public/.htaccess' => 'RewriteEngine On',
            'public/uploads/.gitkeep' => '',
            'public/uploads/.htaccess' => 'deny',
            'public/uploads/photo.jpg' => 'jpg',
            'public/uploads/.DS_Store' => 'x',
            'build/index.html' => '<html>',
            'build/app-1a2b.js' => 'js',
            'vendor/autoload.php' => '<?php',
            'vendor/composer/installed.json' => '{"packages": [], "dev": true, "dev-package-names": ["phpunit/phpunit"]}',
            '.tmp/data.db' => 'db',
            '.strapi/client/app.js' => 'js',
            'node_modules/vite/index.js' => 'js',
            '.git/HEAD' => 'ref',
            'dist/old.zip' => 'zip',
            'database/migrations/.gitkeep' => '',
        ];
        foreach ($files as $path => $content) {
            if (!is_dir(dirname("{$this->root}/{$path}"))) {
                mkdir(dirname("{$this->root}/{$path}"), 0o755, true);
            }
            file_put_contents("{$this->root}/{$path}", $content);
        }
        chmod("{$this->root}/bin/strapi", 0o755);

        // a Composer path repository: the package is a symlink out of the project
        mkdir($this->outside . '/src', 0o755, true);
        file_put_contents($this->outside . '/src/Strapi.php', '<?php // package');
        mkdir($this->root . '/vendor/hynding');
        symlink($this->outside, $this->root . '/vendor/hynding/strapi-php');
        // a link to its own parent, and a dangling one
        symlink($this->root . '/vendor', $this->root . '/vendor/hynding/loop');
        symlink($this->root . '/nowhere', $this->root . '/vendor/dangling');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg(dirname($this->root)));
    }

    public function testListsTheProjectWithoutItsDataSecretsAndToolchain(): void
    {
        $files = Bundle::files($this->root);

        self::assertSame([
            '.env.example', 'bin/strapi', 'build/app-1a2b.js', 'build/index.html', 'composer.json', 'config/server.php',
            'database/migrations/.gitkeep', 'package.json', 'public/.htaccess', 'public/index.php', 'public/uploads/.gitkeep',
            'public/uploads/.htaccess', 'src/index.php', 'vendor/autoload.php', 'vendor/composer/installed.json',
            'vendor/hynding/strapi-php/src/Strapi.php',
        ], array_keys($files));
        self::assertSame(realpath($this->outside . '/src/Strapi.php'), $files['vendor/hynding/strapi-php/src/Strapi.php'], 'symlinks are archived as their files');
    }

    public function testWritesAZip(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip is not installed');
        }
        $archive = dirname($this->root) . '/out/my-app.zip';

        $result = Bundle::create($this->root, $archive);

        self::assertSame(16, $result['files']);
        self::assertSame(filesize($archive), $result['bytes']);
        self::assertCount(2, $result['warnings']);
        self::assertStringContainsString('public/admin/ was not written by `strapi build`', $result['warnings'][0]);
        self::assertStringContainsString('development packages (phpunit/phpunit)', $result['warnings'][1]);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive));
        self::assertSame('<?php // package', $zip->getFromName('my-app/vendor/hynding/strapi-php/src/Strapi.php'), 'entries are under the package name');
        self::assertFalse($zip->getFromName('my-app/.env'));
        self::assertTrue($zip->getExternalAttributesName('my-app/bin/strapi', $system, $attributes));
        self::assertSame(0o755, ($attributes >> 16) & 0o777, 'bin/strapi stays executable');
        $zip->close();
    }

    public function testWritesATarGz(): void
    {
        PublicAdminAssets::publish($this->root . '/build', $this->root . '/public', '/admin', new \Strapi\Cli\Cli\Utils\Logger(silent: true));
        $archive = $this->root . '/dist/my-app.tar.gz';

        $result = Bundle::create($this->root, $archive, ['name' => 'site']);

        self::assertCount(1, $result['warnings'], 'public/admin is the build\'s');
        $list = [];
        exec('tar -tzvf ' . escapeshellarg($archive), $list, $exit);
        self::assertSame(0, $exit);
        $listing = implode("\n", $list);
        self::assertStringContainsString('site/public/admin/app-1a2b.js', $listing);
        self::assertStringContainsString('site/vendor/hynding/strapi-php/src/Strapi.php', $listing);
        self::assertStringNotContainsString('site/dist/', $listing, 'not dist/, nor the archive itself');
        self::assertMatchesRegularExpression('/^-rwxr-xr-x .* site\/bin\/strapi$/m', $listing);
    }

    public function testNeedsVendorAndABuiltAdmin(): void
    {
        unlink($this->root . '/build/index.html');
        try {
            Bundle::create($this->root, $this->root . '/dist/x.zip');
            self::fail('no build');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('php bin/strapi build', $error->getMessage());
        }
        self::assertSame(15, Bundle::create($this->root, $this->root . '/dist/x.tar.gz', ['withoutAdmin' => true])['files']);

        unlink($this->root . '/vendor/autoload.php');
        $this->expectExceptionMessage('composer install');
        Bundle::create($this->root, $this->root . '/dist/x.zip', ['withoutAdmin' => true]);
    }

    public function testRefusesAnUnknownFormat(): void
    {
        $this->expectExceptionMessage('use .zip or .tar.gz');
        Bundle::create($this->root, $this->root . '/dist/x.rar');
    }
}
