<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Strapi\Utils;

use Strapi\DataTransfer\Strapi\Utils\ProjectSettingsLogos;
use Strapi\DataTransfer\Tests\BootedAppTestCase;

/**
 * Port of src/strapi/utils/__tests__/project-settings-logos.test.ts (the local-provider cases),
 * against the fixture app and its real local upload provider instead of a mocked `strapi`. The
 * remote-provider cases are covered by tests/api/core/data-transfer/project-settings-logos.
 */
final class ProjectSettingsLogosTest extends BootedAppTestCase
{
    protected function setUp(): void
    {
        file_put_contents(self::$appDir . '/public/uploads/menu_logo.png', 'menu-logo');
        file_put_contents(self::$appDir . '/public/uploads/auth_logo.png', 'auth-logo');
    }

    protected function tearDown(): void
    {
        foreach (['menu_logo.png', 'auth_logo.png'] as $file) {
            @unlink(self::$appDir . "/public/uploads/{$file}");
        }
    }

    /** @return array<string, mixed> */
    private static function logo(string $name): array
    {
        return [
            'name' => "{$name}-logo.png",
            'hash' => "{$name}_logo",
            'url' => "/uploads/{$name}_logo.png",
            'width' => 100,
            'height' => 100,
            'ext' => '.png',
            'size' => 123,
            'provider' => 'local',
        ];
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function row(array $value): array
    {
        return ['id' => 1, 'key' => ProjectSettingsLogos::PROJECT_SETTINGS_CORE_STORE_KEY, 'type' => 'object', 'environment' => null, 'tag' => null, 'value' => $value];
    }

    public function testExportsAdminLogoFileContentsAlongsideProjectSettingsConfiguration(): void
    {
        $exported = ProjectSettingsLogos::enrichProjectSettingsForExport(self::strapi(), self::row(['menuLogo' => self::logo('menu'), 'authLogo' => self::logo('auth')]));

        self::assertSame(base64_encode('menu-logo'), $exported['value']['menuLogo']['__transferBuffer']);
        self::assertSame(base64_encode('auth-logo'), $exported['value']['authLogo']['__transferBuffer']);
    }

    public function testDoesNotEnrichUnrelatedCoreStoreRowsOnExport(): void
    {
        $row = ['id' => 2, 'key' => 'plugin_upload_settings', 'type' => 'object', 'value' => ['menuLogo' => self::logo('menu')]];

        self::assertSame($row, ProjectSettingsLogos::enrichProjectSettingsForExport(self::strapi(), $row));
    }

    public function testExportLeavesNullLogosUnchanged(): void
    {
        $row = self::row(['menuLogo' => null, 'authLogo' => null]);

        self::assertSame($row, ProjectSettingsLogos::enrichProjectSettingsForExport(self::strapi(), $row));
    }

    public function testExportSkipsTheBufferWhenTheLogoFileIsMissingOnDisk(): void
    {
        unlink(self::$appDir . '/public/uploads/menu_logo.png');

        $exported = ProjectSettingsLogos::enrichProjectSettingsForExport(self::strapi(), self::row(['menuLogo' => self::logo('menu'), 'authLogo' => null]));

        self::assertArrayNotHasKey('__transferBuffer', $exported['value']['menuLogo']);
        self::assertSame('/uploads/menu_logo.png', $exported['value']['menuLogo']['url']);
    }

    public function testRestoreLeavesLogoMetadataUnchangedWhenNoTransferBufferIsPresent(): void
    {
        $settings = ['menuLogo' => self::logo('menu'), 'authLogo' => null];

        self::assertSame($settings, ProjectSettingsLogos::restoreProjectSettingsLogos(self::strapi(), $settings));
    }

    public function testRestoreUploadsAdminLogosWithTheDestinationProviderAndStripsTransferBuffers(): void
    {
        unlink(self::$appDir . '/public/uploads/menu_logo.png');
        $settings = ['menuLogo' => [...self::logo('menu'), '__transferBuffer' => base64_encode('restored-menu-logo')], 'authLogo' => null];

        $restored = ProjectSettingsLogos::restoreProjectSettingsLogos(self::strapi(), $settings);

        self::assertArrayNotHasKey('__transferBuffer', $restored['menuLogo']);
        self::assertSame('/uploads/menu_logo.png', $restored['menuLogo']['url']);
        self::assertSame('local', $restored['menuLogo']['provider']);
        self::assertNull($restored['authLogo']);
        self::assertSame('restored-menu-logo', file_get_contents(self::$appDir . '/public/uploads/menu_logo.png'));
    }
}
