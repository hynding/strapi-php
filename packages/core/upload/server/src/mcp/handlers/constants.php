<?php

declare(strict_types=1);

namespace Strapi\Upload\Mcp\Handlers;

/** Port of server/src/mcp/handlers/constants.ts. */
final class Constants
{
    public const string MCP_NOT_FOUND_ASSET = 'Media asset not found.';

    public const string MCP_UPDATE_ASSET_NO_FIELDS = 'Provide at least one field to update: name, alternativeText or caption. To move an asset between folders use media_move_assets; url, mime, size and the file contents are owned by the upload provider and cannot be edited over MCP.';

    public const string MCP_NOT_FOUND_FOLDER = 'Media folder not found.';

    public const string MCP_FOLDER_NAME_TAKEN = 'A folder with this name already exists in the same parent folder. Folder names must be unique among siblings.';

    public const string MCP_PARENT_FOLDER_NOT_FOUND = 'The parent folder does not exist. Use media_list_folders to discover valid folder ids, or pass null for the media library root.';

    public const string MCP_FOLDER_MOVE_INTO_SELF = 'A folder cannot be moved into itself or into one of its own descendants.';

    /**
     * Returned when `media_delete_folder` is handed any id that does not resolve to a folder.
     *
     * @param list<int> $ids
     */
    public static function MCP_DELETE_FOLDER_UNRESOLVED_IDS(array $ids): string
    {
        return 'These ids do not match any media folder: ' . implode(', ', $ids) . '. Nothing was deleted — media_delete_folder rejects the whole request rather than deleting the folders that did match, because folder ids and asset ids are indistinguishable integers. If these are asset ids, use media_delete_assets instead; otherwise the folders may already be gone. Use media_list_folders to discover valid folder ids.';
    }

    public const string MCP_MOVE_ASSETS_DESTINATION_NOT_FOUND = 'The destination folder does not exist. Use media_list_folders to discover valid folder ids, or pass null for the media library root.';

    /** Per-id `failed` reasons for `media_move_assets`. */
    public const string MCP_MOVE_ASSETS_ID_NOT_FOUND = 'No media asset has this id, so nothing was moved for it. If this is a folder id, use media_move_folder — and note that a folder id only fails like this when no asset happens to share the number; when one does, media_move_assets moves that asset instead. Otherwise the asset may already be deleted — use media_list_assets to discover valid asset ids.';

    public const string MCP_MOVE_ASSETS_ID_FORBIDDEN = 'This token is not allowed to edit this asset. A permission condition on plugin::upload.assets.update excludes it.';

    /** Returned when the move of one asset failed for another reason (a DB error, a provider fault). */
    public static function MCP_MOVE_ASSETS_ID_FAILED(string $cause): string
    {
        return "Moving this asset failed: {$cause}. This is not a problem with the id itself — the asset exists and this token may edit it — so retrying may succeed. Any assets listed under `moved` were still moved.";
    }

    /** Per-id `failed` reasons for `media_delete_assets`, and the dry-run's `failed` reason. */
    public const string MCP_DELETE_ASSETS_ID_NOT_FOUND = 'No media asset has this id, so nothing was deleted for it. If this is a folder id, use media_delete_folder — and note that a folder id only fails like this when no asset happens to share the number; when one does, media_delete_assets deletes that asset instead. Otherwise the asset may already be deleted; use media_list_assets to discover valid asset ids.';

    public const string MCP_DELETE_ASSETS_ID_FORBIDDEN = 'This token is not allowed to delete this asset. A permission condition on plugin::upload.assets.update excludes it.';

    /** Returned when the deletion of one asset failed for another reason. */
    public static function MCP_DELETE_ASSETS_ID_FAILED(string $cause): string
    {
        return "Deleting this asset failed: {$cause}. The asset may be partially removed — re-read it with media_get_asset before retrying. Any assets listed under `deleted` are gone for good.";
    }
}
