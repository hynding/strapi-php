<?php

declare(strict_types=1);

namespace Strapi\Upload\Tests\Mcp;

use PHPUnit\Framework\TestCase;
use Strapi\Upload\Mcp\Sanitizers\SanitizeMedia;

/** Port of server/src/mcp/__tests__/sanitize-media.test.ts. */
final class SanitizeMediaTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function rawAsset(): array
    {
        return [
            'id' => 7,
            'name' => 'cat.png',
            'alternativeText' => 'A cat',
            'caption' => null,
            'url' => '/uploads/cat_abc.png',
            'mime' => 'image/png',
            'size' => 12.5,
            'width' => 640,
            'height' => 480,
            'ext' => '.png',
            'hash' => 'cat_abc',
            'formats' => ['thumbnail' => []],
            'provider' => 'aws-s3',
            'provider_metadata' => ['secret' => 'x'],
            'folderPath' => '/1',
            'related' => [],
            'folder' => ['id' => 3, 'name' => 'Pets', 'path' => '/1'],
            'createdAt' => '2024-01-01T00:00:00.000Z',
            'updatedAt' => '2024-01-02T00:00:00.000Z',
        ];
    }

    public function testExposesExactlyTheAllowlistedKeys(): void
    {
        self::assertSame(
            ['id', 'name', 'alternativeText', 'caption', 'url', 'mime', 'size', 'width', 'height', 'ext', 'folder', 'createdAt', 'updatedAt'],
            array_keys(SanitizeMedia::sanitizeMediaAsset(self::rawAsset())),
        );
    }

    public function testDropsProviderAndBookkeepingFields(): void
    {
        $sanitized = SanitizeMedia::sanitizeMediaAsset(self::rawAsset());

        foreach (['provider', 'provider_metadata', 'hash', 'formats', 'related', 'folderPath'] as $key) {
            self::assertArrayNotHasKey($key, $sanitized);
        }
    }

    public function testReducesTheFolderRelationToIdAndName(): void
    {
        self::assertSame(['id' => 3, 'name' => 'Pets'], SanitizeMedia::sanitizeMediaAsset(self::rawAsset())['folder']);
        self::assertNull(SanitizeMedia::sanitizeMediaAsset([...self::rawAsset(), 'folder' => null])['folder']);
    }

    public function testSerializesDateTimestampsToIsoStrings(): void
    {
        $sanitized = SanitizeMedia::sanitizeMediaAsset([...self::rawAsset(), 'createdAt' => new \DateTimeImmutable('2024-03-04T05:06:07.008Z')]);

        self::assertSame('2024-03-04T05:06:07.008Z', $sanitized['createdAt']);
    }

    public function testNormalizesMissingImageDimensionsToNull(): void
    {
        $asset = self::rawAsset();
        unset($asset['width'], $asset['height']);

        $sanitized = SanitizeMedia::sanitizeMediaAsset($asset);
        self::assertNull($sanitized['width']);
        self::assertNull($sanitized['height']);
    }

    public function testFolderTree(): void
    {
        self::assertSame(
            [['id' => 1, 'name' => 'a', 'children' => [['id' => 2, 'name' => 'b', 'children' => [['id' => 3, 'name' => 'c', 'children' => []]]]]]],
            SanitizeMedia::sanitizeMediaFolderTree([['id' => 1, 'name' => 'a', 'path' => '/1', 'pathId' => 1, 'children' => [['id' => 2, 'name' => 'b', 'children' => [['id' => 3, 'name' => 'c']]]]]]),
        );
        self::assertSame([], SanitizeMedia::sanitizeMediaFolderTree('nope'));
    }

    public function testSanitizeMediaFolder(): void
    {
        $folder = ['id' => 4, 'name' => 'Docs', 'path' => '/1/4', 'pathId' => 4, 'createdAt' => 'c', 'updatedAt' => 'u'];

        self::assertSame(['id' => 4, 'name' => 'Docs', 'createdAt' => 'c', 'updatedAt' => 'u'], SanitizeMedia::sanitizeMediaFolder($folder));
        self::assertSame(['id' => 1, 'name' => 'Root'], SanitizeMedia::sanitizeMediaFolder([...$folder, 'parent' => ['id' => 1, 'name' => 'Root']])['parent'] ?? null);
        self::assertSame(['id' => 1], SanitizeMedia::sanitizeMediaFolder([...$folder, 'parent' => 1])['parent'] ?? null);
        self::assertArrayHasKey('parent', SanitizeMedia::sanitizeMediaFolder([...$folder, 'parent' => null]) ?? []);
        $root = SanitizeMedia::sanitizeMediaFolder([...$folder, 'parent' => null]) ?? [];
        self::assertNull($root['parent']);
        self::assertNull(SanitizeMedia::sanitizeMediaFolder(null));
    }
}
