<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Strapi\Providers\LocalDestination;

require_once __DIR__ . '/../../../BootedAppTestCase.php';

use Strapi\DataTransfer\Strapi\Providers\LocalDestination\LocalDestination;
use Strapi\DataTransfer\Tests\BootedAppTestCase;

/**
 * PHP port: restoring assets moves `public/uploads` aside and starts an empty one. The directory's
 * `.htaccess` (no uploaded file may run as PHP under Apache) comes along.
 */
final class AssetsBackupTest extends BootedAppTestCase
{
    public function testTheNewUploadsDirectoryKeepsItsHtaccess(): void
    {
        $uploads = self::$appDir . '/public/uploads';
        file_put_contents("{$uploads}/.htaccess", "Require all denied\n");
        file_put_contents("{$uploads}/old.png", 'old');

        $provider = new LocalDestination(['restore' => ['assets' => true]]);
        $provider->strapi = self::strapi();
        self::strapi()->config()->set('plugin::upload.provider', 'local');
        (new \ReflectionMethod($provider, 'handleAssetsBackup'))->invoke($provider);

        $backup = self::$appDir . '/public/' . $provider->uploadsBackupDirectoryName;
        try {
            self::assertFileExists("{$backup}/old.png");
            self::assertFileDoesNotExist("{$uploads}/old.png");
            self::assertFileExists("{$uploads}/.gitkeep");
            self::assertSame("Require all denied\n", file_get_contents("{$uploads}/.htaccess"));
        } finally {
            array_map('unlink', glob("{$backup}/{,.}[!.]*", GLOB_BRACE) ?: []);
            @rmdir($backup);
        }
    }
}
