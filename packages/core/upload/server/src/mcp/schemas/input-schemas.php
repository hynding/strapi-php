<?php

declare(strict_types=1);

namespace Strapi\Upload\Mcp\Schemas;

use Strapi\Upload\Constants;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodNumber;
use Strapi\Utils\Zod\ZodObject;
use Strapi\Utils\Zod\ZodOptional;
use Strapi\Utils\Zod\ZodString;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of server/src/mcp/schemas/input-schemas.ts. Each exported schema is a static factory.
 *
 * Media files and folders are NOT documents: they are plain entities keyed by a numeric `id`,
 * so there is no `documentId` and no draft/published pair. Every media identifier in the MCP
 * surface is this numeric id.
 */
final class InputSchemas
{
    private const array FOLDER_INPUT_KEYS = ['folder', 'folderId', 'folderPath'];

    public static function mediaIdSchema(): ZodNumber
    {
        return z::number()
            ->int()
            ->min(1)
            ->describe('Numeric media asset id (e.g. 42). Media files are not documents — they have no documentId and no draft/published versions.');
    }

    public static function folderIdSchema(): ZodNumber
    {
        return z::number()
            ->int()
            ->min(1)
            ->describe('Numeric media folder id (e.g. 3). Folders are not documents — they use numeric ids.');
    }

    public static function pageSchema(): ZodOptional
    {
        return z::number()->int()->min(1)->optional()->describe('Page number (1-indexed, default: 1).');
    }

    public static function pageSizeSchema(): ZodOptional
    {
        return z::number()->int()->min(1)->max(100)->optional()->describe('Items per page (default: 25, max: 100).');
    }

    /**
     * Sort is constrained to the same whitelist the Media Library admin uses, so MCP callers
     * cannot sort by private columns such as `folderPath`.
     */
    public static function sortSchema(): ZodOptional
    {
        return z::enum(Constants::ALLOWED_SORT_STRINGS)
            ->optional()
            ->describe('Sort expression. One of: ' . implode(', ', Constants::ALLOWED_SORT_STRINGS) . '. Defaults to "createdAt:DESC".');
    }

    public static function mediaListAssetsInputSchema(): ZodObject
    {
        return z::object([
            'folderId' => self::folderIdSchema()
                ->optional()
                ->describe('Only return assets directly inside this folder. Omit for every folder; pass null for assets at the media library root.')
                ->nullable(),
            'mime' => z::string()
                ->min(1)
                ->optional()
                ->describe('Filter by mime type prefix or exact value (e.g. "image", "image/png", "application/pdf").'),
            'name' => z::string()
                ->min(1)
                ->optional()
                ->describe('Case-insensitive substring search on the asset name.'),
            'page' => self::pageSchema(),
            'pageSize' => self::pageSizeSchema(),
            'sort' => self::sortSchema(),
        ]);
    }

    public static function mediaGetAssetInputSchema(): ZodObject
    {
        return z::object([
            'id' => self::mediaIdSchema(),
        ]);
    }

    public static function mediaListFoldersInputSchema(): ZodObject
    {
        return z::object([]);
    }

    /**
     * @param list<string> $keys
     * @return \Closure(array<string, mixed>): ?string
     */
    private static function unrecognizedKeysError(array $keys, string $message): \Closure
    {
        return static function (array $issue) use ($keys, $message): ?string {
            $issueKeys = is_array($issue['keys'] ?? null) ? $issue['keys'] : [];
            if (($issue['code'] ?? null) === 'unrecognized_keys' && array_intersect($keys, $issueKeys) !== []) {
                return $message;
            }

            return null;
        };
    }

    /**
     * `media_update_asset` input — the only writable asset metadata. `strict()` turns an
     * out-of-scope field into an error rather than a silent no-op; folder-shaped inputs are
     * directed to `media_move_assets`. The "at least one field" rule is enforced in the handler.
     */
    public static function mediaUpdateAssetInputSchema(): ZodObject
    {
        return z::object(
            [
                'id' => self::mediaIdSchema(),
                'name' => z::string()
                    ->min(1)
                    ->optional()
                    ->describe('New asset name as shown in the Media Library. Renames the entry only — the stored file and its URL are unchanged.'),
                'alternativeText' => z::string()
                    ->nullable()
                    ->optional()
                    ->describe('Alt text used by the frontend for accessibility. Pass null to clear it; the field then reads back as an empty string.'),
                'caption' => z::string()
                    ->nullable()
                    ->optional()
                    ->describe('Caption shown alongside the asset. Pass null to clear it; the field then reads back as an empty string.'),
            ],
            ['error' => self::unrecognizedKeysError(self::FOLDER_INPUT_KEYS, 'Folder changes are not supported by media_update_asset. Use media_move_assets to move an asset between folders.')],
        )->strict();
    }

    /**
     * Folder name, validated to the same rules as the admin folder controller: non-empty, no
     * slashes, no surrounding whitespace. Uniqueness needs a DB read, so it stays in the handler.
     */
    public static function folderNameSchema(): ZodString
    {
        return z::string()
            ->min(1)
            ->regex('/^[^\/]+$/', 'Folder name cannot contain slashes.')
            ->regex('/^(?! ).+(?<! )$/s', 'Folder name cannot start or end with a whitespace.')
            ->describe('Folder name as shown in the Media Library. Cannot contain slashes or start/end with a space, and must be unique among its siblings.');
    }

    /** `parent` is nullable-with-meaning: null is the media library root, an id nests the folder. */
    private static function parentFolderIdSchema(): ZodType
    {
        return self::folderIdSchema()
            ->nullable()
            ->describe('Numeric id of the containing folder. Pass null for the media library root. Use media_list_folders to discover folder ids.');
    }

    public static function mediaCreateFolderInputSchema(): ZodObject
    {
        return z::object([
            'name' => self::folderNameSchema(),
            'parent' => self::parentFolderIdSchema()
                ->optional()
                ->describe('Numeric id of the parent folder. Omit or pass null to create the folder at the media library root.'),
        ])->strict();
    }

    /** `media_rename_folder` deliberately takes no `parent`: renaming and moving are separate tools. */
    public static function mediaRenameFolderInputSchema(): ZodObject
    {
        return z::object(
            [
                'id' => self::folderIdSchema(),
                'name' => self::folderNameSchema(),
            ],
            ['error' => self::unrecognizedKeysError(['parent'], 'media_rename_folder only changes a folder name. Use media_move_folder to change a folder location.')],
        )->strict();
    }

    /** `media_move_folder` requires `parent` — including an explicit null for the root. */
    public static function mediaMoveFolderInputSchema(): ZodObject
    {
        return z::object(
            [
                'id' => self::folderIdSchema(),
                'parent' => self::parentFolderIdSchema(),
            ],
            ['error' => self::unrecognizedKeysError(['name'], 'media_move_folder only changes a folder location. Use media_rename_folder to change a folder name.')],
        )->strict();
    }

    /**
     * `media_delete_folder` input. `dryRun` defaults to true in the handler, not in this schema.
     */
    public static function mediaDeleteFolderInputSchema(): ZodObject
    {
        return z::object([
            'ids' => z::array(self::folderIdSchema())
                ->min(1)
                ->max(100)
                ->describe('Numeric ids of the folders to delete (1-100). FOLDER ids only — asset ids are a separate namespace of integers and are rejected here; use media_delete_assets for assets.'),
            'dryRun' => z::boolean()
                ->optional()
                ->describe('When true (the default), nothing is deleted and the tool only reports how many folders and files WOULD be removed. Pass false to actually perform the irreversible deletion.'),
        ])->strict();
    }

    /** @return \Closure(array<string, mixed>): ?string */
    private static function bulkAssetsError(string $bulk, string $assetsOnly): \Closure
    {
        return static function (array $issue) use ($bulk, $assetsOnly): ?string {
            $keys = is_array($issue['keys'] ?? null) ? $issue['keys'] : [];
            if (($issue['code'] ?? null) === 'unrecognized_keys' && (in_array('id', $keys, true) || in_array('fileIds', $keys, true))) {
                return $bulk;
            }
            if (($issue['code'] ?? null) === 'unrecognized_keys' && in_array('folderIds', $keys, true)) {
                return $assetsOnly;
            }

            return null;
        };
    }

    /**
     * `media_move_assets` input. ASSET ids only, and the schema CANNOT enforce it (see the tool
     * description).
     */
    public static function mediaMoveAssetsInputSchema(): ZodObject
    {
        return z::object(
            [
                'ids' => z::array(self::mediaIdSchema())
                    ->min(1)
                    ->max(100)
                    ->describe('Numeric ids of the assets to move (1-100). ASSET ids only, taken from media_list_assets or media_get_asset — never from media_list_folders. Folder ids are numbered separately and the same number often names both an asset and a folder, so a folder id here moves the asset sharing that number; use media_move_folder to move a folder.'),
                'folder' => self::folderIdSchema()
                    ->nullable()
                    ->describe('Numeric id of the destination folder. Pass null to move the assets to the media library root. Required — including the explicit null — so a move always names a destination. Use media_list_folders to discover folder ids.'),
            ],
            ['error' => self::bulkAssetsError(
                'media_move_assets moves assets in bulk: pass `ids` as an array of numeric asset ids, even for a single asset.',
                'media_move_assets moves assets only. Use media_move_folder to move a folder.',
            )],
        )->strict();
    }

    /**
     * `media_delete_assets` input. `dryRun` defaults to true in the handler.
     */
    public static function mediaDeleteAssetsInputSchema(): ZodObject
    {
        return z::object(
            [
                'ids' => z::array(self::mediaIdSchema())
                    ->min(1)
                    ->max(100)
                    ->describe('Numeric ids of the assets to delete (1-100). ASSET ids only, taken from media_list_assets or media_get_asset — never from media_list_folders. Folder ids are numbered separately and the same number often names both an asset and a folder, so a folder id here deletes the asset sharing that number; use media_delete_folder for folders.'),
                'dryRun' => z::boolean()
                    ->optional()
                    ->describe('When true (the default), NOTHING is deleted and the tool only reports which assets WOULD be permanently removed. Pass false to actually perform the irreversible deletion.'),
            ],
            ['error' => self::bulkAssetsError(
                'media_delete_assets deletes assets in bulk: pass `ids` as an array of numeric asset ids, even for a single asset.',
                'media_delete_assets deletes assets only. Use media_delete_folder to delete a folder.',
            )],
        )->strict();
    }
}
