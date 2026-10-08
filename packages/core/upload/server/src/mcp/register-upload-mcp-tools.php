<?php

declare(strict_types=1);

namespace Strapi\Upload\Mcp;

use Strapi\Core\Strapi;
use Strapi\Upload\Constants;
use Strapi\Upload\Mcp\Schemas\InputSchemas;
use Strapi\Upload\Mcp\Schemas\OutputSchemas;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of server/src/mcp/register-upload-mcp-tools.ts: the Media Library MCP tools.
 *
 * Folder writes inherit the same `plugin::upload.assets.update` action rather than introducing a
 * folder-specific one, matching what the admin UI enforces today. MCP-specific folder RBAC is
 * out of scope.
 *
 * Renaming and moving are separate tools for both objects — rename/update changes attributes,
 * move changes location — so an agent selects by intent instead of assembling a combined patch.
 *
 * The asset and folder tools stay separate for both move and delete: asset ids and folder ids are
 * indistinguishable integers from separate namespaces, so a combined tool would let an agent pass
 * folder ids where assets were meant with nothing to object.
 *
 * Core's MCP service (`strapi.ai.mcp`) is a stub in the PHP port: while it has no
 * `registerTool()`, registration is a no-op, as upstream when the MCP server is disabled.
 *
 * @phpstan-import-type UploadMcpTool from Types
 */
final class RegisterUploadMcpTools
{
    /** @return list<UploadMcpTool> */
    public static function buildUploadMcpToolDefinitions(): array
    {
        return [
            [
                'name' => 'media_list_assets',
                'title' => 'Media: list assets',
                'description' => "List Media Library assets with pagination, folder / mime type / name filters and sorting. Assets are identified by a numeric id — media files are not documents and have no documentId.",
                'telemetry' => ['source' => 'upload', 'name' => 'list'],
                'auth' => ['policies' => [['action' => Constants::ACTIONS['read']]]],
                'resolveInputSchema' => static fn (): ZodType => InputSchemas::mediaListAssetsInputSchema(),
                'resolveOutputSchema' => static fn (): ZodType => OutputSchemas::mediaListAssetsOutputSchema(),
                'createHandler' => Handlers\ReadHandlers::createMediaListAssetsHandler(...),
            ],
            [
                'name' => 'media_get_asset',
                'title' => 'Media: get asset',
                'description' => "Get a single Media Library asset by its numeric id. Media files are not documents: use the numeric id, not a documentId.",
                'telemetry' => ['source' => 'upload', 'name' => 'get'],
                'auth' => ['policies' => [['action' => Constants::ACTIONS['read']]]],
                'resolveInputSchema' => static fn (): ZodType => InputSchemas::mediaGetAssetInputSchema(),
                'resolveOutputSchema' => static fn (): ZodType => OutputSchemas::mediaGetAssetOutputSchema(),
                'createHandler' => Handlers\ReadHandlers::createMediaGetAssetHandler(...),
            ],
            [
                'name' => 'media_list_folders',
                'title' => 'Media: list folders',
                'description' => "List the Media Library folder structure as a nested tree. Folders are identified by a numeric id; pass one as `folderId` to media_list_assets to list its contents.",
                'telemetry' => ['source' => 'upload', 'name' => 'list_folders'],
                'auth' => ['policies' => [['action' => Constants::ACTIONS['read']]]],
                'resolveOutputSchema' => static fn (): ZodType => OutputSchemas::mediaListFoldersOutputSchema(),
                'createHandler' => Handlers\ReadHandlers::createMediaListFoldersHandler(...),
            ],
            [
                'name' => 'media_update_asset',
                'title' => 'Media: update asset metadata',
                'description' => "Update the editable metadata of a Media Library asset, identified by its numeric id. Only name, alternativeText and caption can be written: use media_move_assets to change an asset's folder, and note that url, mime, size and the file contents are owned by the upload provider and cannot be edited over MCP.",
                'telemetry' => ['source' => 'upload', 'name' => 'update'],
                'auth' => ['policies' => [['action' => Constants::ACTIONS['update']]]],
                'resolveInputSchema' => static fn (): ZodType => InputSchemas::mediaUpdateAssetInputSchema(),
                'resolveOutputSchema' => static fn (): ZodType => OutputSchemas::mediaUpdateAssetOutputSchema(),
                'createHandler' => Handlers\WriteHandlers::createMediaUpdateAssetHandler(...),
            ],
            [
                'name' => 'media_move_assets',
                'title' => 'Media: move assets between folders',
                'description' => "Move Media Library assets into a different folder, in bulk. Takes `ids` — an array of numeric ASSET ids — and `folder`, the numeric id of the destination; pass `folder: null` to move them to the media library root. Both are required: use an array of one to move a single asset, and say null explicitly for the root.\n\nTakes ASSET ids only. Media files are not documents: use numeric ids, not documentIds.\n\nNEVER PASS A FOLDER ID. Asset ids and folder ids are separate, independently numbered namespaces, and the SAME NUMBER OFTEN NAMES BOTH an asset and a folder. This tool always reads the number as an ASSET id: hand it a folder id and it will move whichever unrelated asset happens to share that number, reporting that move as a success, while leaving the folder exactly where it was. Nothing in the request can express which one you meant, so the server cannot catch this for you — only an id matching no asset at all is reported as failed. Take ids only from media_list_assets or media_get_asset, never from media_list_folders, and use media_move_folder to move a folder (which carries its whole subtree).\n\nMoving changes the folder only. Names, alt text, captions and the assets' public URLs are unaffected, so nothing referencing them breaks — use media_update_asset to edit metadata.\n\nPARTIAL SUCCESS IS POSSIBLE: a bad id among good ones does NOT roll the valid moves back. The response always reports `moved` (the assets that were moved) and `failed` (each remaining id with a reason), which together account for every id you passed — so retry only the ids in `failed`, and treat `moved` as done. This holds even when nothing moved at all: `moved` is then empty and every id is in `failed`. The one error that rejects the whole call is a destination folder that does not exist, which is checked before anything is moved.",
                'telemetry' => ['source' => 'upload', 'name' => 'move'],
                'auth' => ['policies' => [['action' => Constants::ACTIONS['update']]]],
                'resolveInputSchema' => static fn (): ZodType => InputSchemas::mediaMoveAssetsInputSchema(),
                'resolveOutputSchema' => static fn (): ZodType => OutputSchemas::mediaMoveAssetsOutputSchema(),
                'createHandler' => Handlers\WriteHandlers::createMediaMoveAssetsHandler(...),
            ],
            [
                'name' => 'media_delete_assets',
                'title' => 'Media: delete assets (destructive)',
                'description' => "DESTRUCTIVE AND IRREVERSIBLE. Permanently deletes Media Library assets by numeric id, in bulk — from the database AND from the storage provider, along with every generated thumbnail and size variant. There is no undo, no trash and no recycle bin. The deleted files stop being served immediately, so any live entry, page or export still referencing one will break.\n\nWHETHER AN ASSET IS USED IN PUBLISHED CONTENT CANNOT BE CHECKED: Strapi does not expose \"used in\" information over this API, so this tool cannot tell you whether an asset is referenced by any entry, and a successful delete is NOT evidence that nothing was using it. Confirm with the user before deleting.\n\nCall it first WITHOUT `dryRun` (or with `dryRun: true`) to preview: nothing is deleted and the response lists exactly which assets WOULD be removed, and how many. Report those to the user, and only then call it again with `dryRun: false` to actually delete.\n\nTakes `ids`, an array of numeric ASSET ids — use an array of one to delete a single asset. Media files are not documents: use numeric ids, not documentIds.\n\nNEVER PASS A FOLDER ID. Asset ids and folder ids are separate, independently numbered namespaces, and the SAME NUMBER OFTEN NAMES BOTH an asset and a folder. This tool always reads the number as an ASSET id: hand it a folder id and it will silently delete whichever unrelated asset happens to share that number, while leaving the folder untouched. Nothing in the request can express which one you meant, so the server cannot catch this for you — only an id matching no asset at all is reported as failed. Take ids only from media_list_assets or media_get_asset, never from media_list_folders, and use media_delete_folder to delete a folder. If you are not certain an id came from media_list_assets, run the dry run and check the returned name and folder before deleting.\n\nPARTIAL SUCCESS IS POSSIBLE: a bad id among good ones does NOT roll the completed deletions back, and those cannot be undone. The response always reports `deleted` (the assets removed, described in full because they can no longer be read back) and `failed` (each remaining id with a reason), which together account for every id you passed — so retry only the ids in `failed`. This holds on the dry run too, which reports the same split before anything is destroyed.",
                'telemetry' => ['source' => 'upload', 'name' => 'delete'],
                'auth' => ['policies' => [['action' => Constants::ACTIONS['update']]]],
                'resolveInputSchema' => static fn (): ZodType => InputSchemas::mediaDeleteAssetsInputSchema(),
                'resolveOutputSchema' => static fn (): ZodType => OutputSchemas::mediaDeleteAssetsOutputSchema(),
                'createHandler' => Handlers\WriteHandlers::createMediaDeleteAssetsHandler(...),
            ],
            [
                'name' => 'media_create_folder',
                'title' => 'Media: create folder',
                'description' => "Create a Media Library folder, optionally inside an existing one. Folders are identified by a numeric id: pass `parent` to nest the new folder, or omit it to create the folder at the media library root. The name must be unique among its siblings and cannot contain slashes.",
                'telemetry' => ['source' => 'upload', 'name' => 'create_folder'],
                'auth' => ['policies' => [['action' => Constants::ACTIONS['create']]]],
                'resolveInputSchema' => static fn (): ZodType => InputSchemas::mediaCreateFolderInputSchema(),
                'resolveOutputSchema' => static fn (): ZodType => OutputSchemas::mediaCreateFolderOutputSchema(),
                'createHandler' => Handlers\FolderHandlers::createMediaCreateFolderHandler(...),
            ],
            [
                'name' => 'media_rename_folder',
                'title' => 'Media: rename folder',
                'description' => "Rename a Media Library folder, identified by its numeric id. Changes the folder name only and leaves its location, its contents and their URLs untouched — use media_move_folder to change which folder it sits in. The new name must be unique among the folder's siblings.",
                'telemetry' => ['source' => 'upload', 'name' => 'rename_folder'],
                'auth' => ['policies' => [['action' => Constants::ACTIONS['update']]]],
                'resolveInputSchema' => static fn (): ZodType => InputSchemas::mediaRenameFolderInputSchema(),
                'resolveOutputSchema' => static fn (): ZodType => OutputSchemas::mediaRenameFolderOutputSchema(),
                'createHandler' => Handlers\FolderHandlers::createMediaRenameFolderHandler(...),
            ],
            [
                'name' => 'media_move_folder',
                'title' => 'Media: move folder',
                'description' => "Move a Media Library folder into a different parent folder, identified by numeric ids. The folder keeps its name and carries all of its subfolders and files with it; pass `parent: null` to move it to the media library root. A folder cannot be moved into itself or into one of its own descendants. Use media_rename_folder to change the name instead.",
                'telemetry' => ['source' => 'upload', 'name' => 'move_folder'],
                'auth' => ['policies' => [['action' => Constants::ACTIONS['update']]]],
                'resolveInputSchema' => static fn (): ZodType => InputSchemas::mediaMoveFolderInputSchema(),
                'resolveOutputSchema' => static fn (): ZodType => OutputSchemas::mediaMoveFolderOutputSchema(),
                'createHandler' => Handlers\FolderHandlers::createMediaMoveFolderHandler(...),
            ],
            [
                'name' => 'media_delete_folder',
                'title' => 'Media: delete folder (destructive)',
                'description' => "DESTRUCTIVE AND IRREVERSIBLE. Deletes Media Library folders by numeric id and CASCADES: every subfolder and every file inside them is permanently deleted from the database and from the storage provider. There is no undo, no trash and no recycle bin, and the deleted files stop being served — any live entry or page still referencing one will break.\n\nWHETHER THE CONTAINED ASSETS ARE USED IN PUBLISHED CONTENT CANNOT BE CHECKED: Strapi does not expose \"used in\" information over this API, so this tool cannot tell you whether a file is referenced by an entry. Confirm with the user before deleting.\n\nCall it first WITHOUT `dryRun` (or with `dryRun: true`) to preview: nothing is deleted and the response reports how many folders and files WOULD be removed. Only after reporting those counts should you call it again with `dryRun: false` to actually delete. Takes FOLDER ids only — asset ids are a separate namespace of integers; use media_delete_assets for individual assets. All or nothing: if ANY id does not resolve to a folder the whole call is rejected and nothing is deleted, so a list mixing folder and asset ids never deletes half of what it names.",
                'telemetry' => ['source' => 'upload', 'name' => 'delete_folder'],
                'auth' => ['policies' => [['action' => Constants::ACTIONS['update']]]],
                'resolveInputSchema' => static fn (): ZodType => InputSchemas::mediaDeleteFolderInputSchema(),
                'resolveOutputSchema' => static fn (): ZodType => OutputSchemas::mediaDeleteFolderOutputSchema(),
                'createHandler' => Handlers\FolderHandlers::createMediaDeleteFolderHandler(...),
            ],
        ];
    }

    /**
     * Registers the Media Library MCP tools via `strapi.ai.mcp.registerTool()`.
     * Must be called from the plugin register phase, before the MCP HTTP server starts.
     */
    public static function registerUploadMcpTools(Strapi $strapi): void
    {
        // No `isEnabled()` gate: registerTool() only stores the definition, and the MCP server never
        // exposes it when disabled, so registering unconditionally is a no-op there.
        $mcp = $strapi->has('ai.mcp') ? $strapi->get('ai.mcp') : null;
        if (!is_object($mcp) || !method_exists($mcp, 'registerTool')) {
            return;
        }

        foreach (self::buildUploadMcpToolDefinitions() as $tool) {
            $mcp->registerTool($tool);
        }
    }
}
