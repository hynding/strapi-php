<?php

declare(strict_types=1);

namespace Strapi\Upload\Tests\Mcp;

use Strapi\Permissions\Engine\Abilities\AbilityBuilder;
use Strapi\Tests\AppTestCase;
use Strapi\Upload\Mcp\Handlers\FolderHandlers;
use Strapi\Upload\Mcp\Handlers\ReadHandlers;
use Strapi\Upload\Mcp\Handlers\WriteHandlers;
use Strapi\Upload\Mcp\RegisterUploadMcpTools;
use Strapi\Upload\Mcp\Schemas\InputSchemas;
use Strapi\Utils\Errors\ValidationError;

/**
 * Covers server/src/mcp/__tests__/{register-upload-mcp-tools,schemas,folder-handlers,write-handlers}.test.ts
 * on a booted app with a super-admin ability (upstream mocks the services).
 */
final class HandlersTest extends AppTestCase
{
    /** @return array{userAbility: mixed, user: null} */
    private static function context(): array
    {
        return ['userAbility' => (new AbilityBuilder())->can('manage', 'all')->build(), 'user' => null];
    }

    public function testToolDefinitions(): void
    {
        $tools = RegisterUploadMcpTools::buildUploadMcpToolDefinitions();

        self::assertSame(
            ['media_list_assets', 'media_get_asset', 'media_list_folders', 'media_update_asset', 'media_move_assets', 'media_delete_assets', 'media_create_folder', 'media_rename_folder', 'media_move_folder', 'media_delete_folder'],
            array_column($tools, 'name'),
        );
        self::assertSame('plugin::upload.assets.create', $tools[6]['auth']['policies'][0]['action']);
        self::assertArrayNotHasKey('resolveInputSchema', $tools[2]);
    }

    public function testInputSchemasRedirectMisusedKeys(): void
    {
        $result = InputSchemas::mediaUpdateAssetInputSchema()->safeParse(['id' => 1, 'folder' => 2]);
        self::assertFalse($result['success']);
        self::assertSame('Folder changes are not supported by media_update_asset. Use media_move_assets to move an asset between folders.', $result['error']->issues[0]['message'] ?? null);

        $result = InputSchemas::mediaRenameFolderInputSchema()->safeParse(['id' => 1, 'name' => 'x', 'parent' => 2]);
        self::assertSame('media_rename_folder only changes a folder name. Use media_move_folder to change a folder location.', $result['error']->issues[0]['message'] ?? null);

        self::assertFalse(InputSchemas::folderNameSchema()->safeParse('a/b')['success']);
        self::assertFalse(InputSchemas::folderNameSchema()->safeParse(' a')['success']);
        self::assertTrue(InputSchemas::mediaListAssetsInputSchema()->safeParse(['folderId' => null, 'sort' => 'name:ASC'])['success']);
        self::assertFalse(InputSchemas::mediaListAssetsInputSchema()->safeParse(['sort' => 'folderPath:ASC'])['success']);
    }

    public function testFolderLifecycle(): void
    {
        $strapi = self::strapi();
        $create = FolderHandlers::createMediaCreateFolderHandler($strapi, self::context());

        $parent = $create(['args' => ['name' => 'mcp-parent']])['structuredContent']['data'];
        self::assertSame(['id', 'name', 'parent', 'createdAt', 'updatedAt'], array_keys($parent));
        self::assertNull($parent['parent']);

        $child = $create(['args' => ['name' => 'mcp-child', 'parent' => $parent['id']]])['structuredContent']['data'];
        self::assertSame($parent['id'], $child['parent']['id']);

        try {
            $create(['args' => ['name' => 'mcp-child', 'parent' => $parent['id']]]);
            self::fail('duplicate name');
        } catch (ValidationError $error) {
            self::assertStringContainsString('already exists', $error->getMessage());
        }

        $rename = FolderHandlers::createMediaRenameFolderHandler($strapi, self::context());
        self::assertSame('mcp-renamed', $rename(['args' => ['id' => $child['id'], 'name' => 'mcp-renamed']])['structuredContent']['data']['name']);

        $move = FolderHandlers::createMediaMoveFolderHandler($strapi, self::context());
        try {
            $move(['args' => ['id' => $parent['id'], 'parent' => $child['id']]]);
            self::fail('move into own subtree');
        } catch (ValidationError $error) {
            self::assertSame('A folder cannot be moved into itself or into one of its own descendants.', $error->getMessage());
        }
        self::assertNull($move(['args' => ['id' => $child['id'], 'parent' => null]])['structuredContent']['data']['parent']);

        $tree = ReadHandlers::createMediaListFoldersHandler($strapi, self::context())()['structuredContent']['data'];
        self::assertContains('mcp-renamed', array_column($tree, 'name'));

        $delete = FolderHandlers::createMediaDeleteFolderHandler($strapi, self::context());
        $preview = $delete(['args' => ['ids' => [$parent['id'], $child['id']]]])['structuredContent'];
        self::assertTrue($preview['dryRun']);
        self::assertSame(2, $preview['totalFolderNumber']);

        try {
            $delete(['args' => ['ids' => [$parent['id'], 999999], 'dryRun' => false]]);
            self::fail('unresolved ids');
        } catch (ValidationError $error) {
            self::assertStringStartsWith('These ids do not match any media folder: 999999.', $error->getMessage());
        }

        $done = $delete(['args' => ['ids' => [$parent['id'], $child['id']], 'dryRun' => false]])['structuredContent'];
        self::assertFalse($done['dryRun']);
        self::assertSame(2, $done['totalFolderNumber']);
    }

    public function testAssetTools(): void
    {
        $strapi = self::strapi();
        $file = $strapi->db()->query('plugin::upload.file')->create(['data' => [
            'name' => 'doc.txt', 'hash' => 'doc_hash', 'ext' => '.txt', 'mime' => 'text/plain', 'size' => 1, 'url' => '/uploads/doc_hash.txt', 'provider' => 'none', 'folderPath' => '/',
        ]]);
        $folder = FolderHandlers::createMediaCreateFolderHandler($strapi, self::context())(['args' => ['name' => 'mcp-assets']])['structuredContent']['data'];

        $get = ReadHandlers::createMediaGetAssetHandler($strapi, self::context());
        self::assertSame('doc.txt', $get(['args' => ['id' => $file['id']]])['structuredContent']['data']['name']);

        $list = ReadHandlers::createMediaListAssetsHandler($strapi, self::context());
        self::assertContains($file['id'], array_column($list(['args' => ['mime' => 'text']])['structuredContent']['results'], 'id'));

        $update = WriteHandlers::createMediaUpdateAssetHandler($strapi, self::context());
        $updated = $update(['args' => ['id' => $file['id'], 'caption' => 'hello', 'alternativeText' => null]])['structuredContent']['data'];
        self::assertSame('hello', $updated['caption']);
        self::assertSame('', $updated['alternativeText']);

        $move = WriteHandlers::createMediaMoveAssetsHandler($strapi, self::context());
        $moved = $move(['args' => ['ids' => [$file['id'], 999999], 'folder' => $folder['id']]])['structuredContent'];
        self::assertSame(['id' => $folder['id'], 'name' => 'mcp-assets'], $moved['destinationFolder']);
        self::assertSame([$file['id']], array_column($moved['moved'], 'id'));
        self::assertSame([999999], array_column($moved['failed'], 'id'));

        $delete = WriteHandlers::createMediaDeleteAssetsHandler($strapi, self::context());
        $preview = $delete(['args' => ['ids' => [$file['id']]]])['structuredContent'];
        self::assertTrue($preview['dryRun']);
        self::assertSame(1, $preview['totalFileNumber']);
        self::assertNotNull($strapi->db()->query('plugin::upload.file')->findOne(['where' => ['id' => $file['id']]]));

        $done = $delete(['args' => ['ids' => [$file['id']], 'dryRun' => false]])['structuredContent'];
        self::assertSame(1, $done['totalFileNumber']);
        self::assertNull($strapi->db()->query('plugin::upload.file')->findOne(['where' => ['id' => $file['id']]]));
    }
}
