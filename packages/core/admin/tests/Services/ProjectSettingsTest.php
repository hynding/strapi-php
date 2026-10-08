<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Services;

require_once __DIR__ . '/../BootedAdminApp.php';

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Services\ProjectSettings;
use Strapi\Admin\Tests\BootedAdminApp;
use Strapi\Core\Strapi;

/**
 * Port of server/src/services/__tests__/project-settings.test.ts. The store is the booted app's
 * core store; the upload provider is a recording stub set on `strapi.plugin('upload').provider`.
 * `parseFilesData` (upload's `formatFileInfo`/`getDimensions`) is exercised by the API suite.
 */
final class ProjectSettingsTest extends TestCase
{
    private static ?Strapi $strapi = null;

    private static mixed $previousProvider = null;

    /** @var object{deleted: list<mixed>, uploaded: list<mixed>} */
    private static object $provider;

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
        $strapi = self::strapi();
        if (!$strapi->hasPlugin('upload')) {
            self::markTestSkipped('the upload plugin is not loaded');
        }

        self::$provider = new class () {
            /** @var list<mixed> */
            public array $deleted = [];

            /** @var list<mixed> */
            public array $uploaded = [];

            public function delete(mixed $file): void
            {
                $this->deleted[] = $file;
            }

            /** @param \ArrayAccess<string, mixed> $file */
            public function uploadStream(\ArrayAccess $file): void
            {
                $this->uploaded[] = $file;
                $file['url'] = '/uploads/' . ($file['hash'] ?? 'x') . ($file['ext'] ?? '');
            }
        };
        self::$previousProvider = $strapi->plugin('upload')->provider;
        $strapi->plugin('upload')->provider = self::$provider;
        $strapi->config()->set('plugin::upload.provider', 'local');
        self::store()->delete(['key' => 'project-settings']);
    }

    protected function tearDown(): void
    {
        if (self::strapi()->hasPlugin('upload')) {
            self::strapi()->plugin('upload')->provider = self::$previousProvider;
        }
    }

    private static function strapi(): Strapi
    {
        return self::$strapi ?? throw new \LogicException('not booted');
    }

    private static function store(): \Strapi\Core\Services\ScopedCoreStore
    {
        return self::strapi()->store()->scoped(['type' => 'core', 'name' => 'admin']);
    }

    private static function service(): ProjectSettings
    {
        return self::strapi()->service('admin::project-settings');
    }

    private const LOGO = ['size' => 24085, 'name' => 'file.png', 'type' => 'image/png', 'provider' => 'local', 'url' => 'file/url'];

    public function testShouldSkipEmptyFilesObjectWithNoError(): void
    {
        self::assertSame([], self::service()->parseFilesData([]));
    }

    public function testShouldReturnProjectSettingsFromStoreOnlyTheRightSubsetOfFields(): void
    {
        self::store()->set(['key' => 'project-settings', 'value' => [
            'menuLogo' => ['name' => 'name', 'hash' => 'hash', 'url' => 'file/url', 'width' => 100, 'height' => 100, 'ext' => 'png', 'size' => 123, 'provider' => 'local'],
            'authLogo' => null,
        ]]);

        self::assertSame([
            'menuLogo' => ['name' => 'name', 'url' => 'file/url', 'width' => 100, 'height' => 100, 'ext' => 'png', 'size' => 123],
            'authLogo' => null,
        ], self::service()->getProjectSettings());
    }

    public function testDoesNotDeleteWhenThereWasNoPreviousFile(): void
    {
        self::service()->deleteOldFiles(['previousSettings' => ['menuLogo' => null, 'authLogo' => null], 'newSettings' => ['menuLogo' => self::LOGO, 'authLogo' => self::LOGO]]);

        self::assertSame([], self::$provider->deleted);
    }

    public function testDoesNotDeleteWhenThereIsNoNewFileUploaded(): void
    {
        $settings = ['menuLogo' => self::LOGO, 'authLogo' => self::LOGO];
        self::service()->deleteOldFiles(['previousSettings' => $settings, 'newSettings' => $settings]);

        self::assertSame([], self::$provider->deleted);
    }

    public function testDeletesWhenInputsAreExplicitelySetToNull(): void
    {
        self::service()->deleteOldFiles(['previousSettings' => ['menuLogo' => self::LOGO, 'authLogo' => self::LOGO], 'newSettings' => ['menuLogo' => null, 'authLogo' => null]]);

        self::assertCount(2, self::$provider->deleted);
    }

    public function testDeletesWhenNewFilesAreUploaded(): void
    {
        $previous = [...self::LOGO, 'hash' => '123'];
        self::service()->deleteOldFiles([
            'previousSettings' => ['menuLogo' => $previous, 'authLogo' => $previous],
            'newSettings' => ['menuLogo' => [...$previous, 'hash' => '456'], 'authLogo' => [...$previous, 'hash' => '456']],
        ]);

        self::assertCount(2, self::$provider->deleted);
    }

    public function testUpdatesTheProjectSettings(): void
    {
        $file = [
            'name' => 'filename.png', 'alternativeText' => null, 'caption' => null, 'hash' => 'filename_123', 'ext' => '.png',
            'mime' => 'image/png', 'size' => 123, 'stream' => null, 'width' => 100, 'height' => 100,
            'tmpPath' => '/tmp/filename_123', 'url' => '/uploads/filename_123.png',
        ];

        self::service()->updateProjectSettings(['menuLogo' => $file, 'authLogo' => $file]);

        $expected = ['name' => 'filename.png', 'hash' => 'filename_123', 'url' => '/uploads/filename_123.png', 'width' => 100, 'height' => 100, 'ext' => '.png', 'size' => 123];
        self::assertSame(['menuLogo' => $expected, 'authLogo' => $expected], self::store()->get(['key' => 'project-settings']));
        self::assertSame([], self::$provider->uploaded, 'no stream: nothing to upload');
    }

    public function testUploadsAStreamThroughTheProvider(): void
    {
        $stream = fopen('php://memory', 'rb');
        self::service()->updateProjectSettings(['menuLogo' => ['name' => 'logo.png', 'hash' => 'logo_1', 'ext' => '.png', 'size' => 1.06, 'width' => 35, 'height' => 35, 'stream' => $stream, 'provider' => 'local']]);

        self::assertCount(1, self::$provider->uploaded);
        self::assertSame('/uploads/logo_1.png', self::service()->getProjectSettings()['menuLogo']['url']);
    }

    public function testUpdatesTheProjectSettingsDelete(): void
    {
        self::service()->updateProjectSettings(['menuLogo' => '', 'authLogo' => '']);

        self::assertSame(['menuLogo' => null, 'authLogo' => null], self::store()->get(['key' => 'project-settings']));
    }

    public function testKeepsThePreviousProjectSettings(): void
    {
        $logo = ['name' => 'name', 'url' => 'file/url', 'width' => 100, 'height' => 100, 'ext' => 'png', 'size' => 123, 'provider' => 'local'];
        self::store()->set(['key' => 'project-settings', 'value' => ['menuLogo' => $logo, 'authLogo' => $logo]]);

        self::service()->updateProjectSettings([]);

        self::assertSame(['menuLogo' => $logo, 'authLogo' => $logo], self::store()->get(['key' => 'project-settings']));
    }
}
