<?php

declare(strict_types=1);

namespace Strapi\Upload\Mcp\Schemas;

use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodObject;
use Strapi\Utils\Zod\ZodType;

/** Port of server/src/mcp/schemas/output-schemas.ts. Each exported schema is a static factory. */
final class OutputSchemas
{
    /**
     * The exhaustive set of asset fields the MCP surface may expose — an allowlist, for the reasons
     * in `sanitizeMediaAsset`.
     */
    public static function mediaAssetOutputSchema(): ZodObject
    {
        return z::object([
            'id' => z::number()->describe('Numeric asset id — the canonical identifier for this asset.'),
            'name' => z::string(),
            'alternativeText' => z::string()->nullable()->optional(),
            'caption' => z::string()->nullable()->optional(),
            'url' => z::string()->describe('Public (or signed, for private providers) URL of the asset.'),
            'mime' => z::string()->describe('Mime type, e.g. "image/png".'),
            'size' => z::number()->describe('File size in kilobytes, as stored by Strapi.'),
            'width' => z::number()->nullable()->optional()->describe('Pixel width, images only.'),
            'height' => z::number()->nullable()->optional()->describe('Pixel height, images only.'),
            'ext' => z::string()->nullable()->optional()->describe('File extension including the dot, e.g. ".png".'),
            'folder' => z::object([
                'id' => z::number(),
                'name' => z::string(),
            ])
                ->nullable()
                ->optional()
                ->describe('Containing folder, or null when the asset sits at the media library root.'),
            'createdAt' => z::string()->nullable()->optional(),
            'updatedAt' => z::string()->nullable()->optional(),
        ]);
    }

    public static function mediaGetAssetOutputSchema(): ZodObject
    {
        return z::object([
            'data' => self::mediaAssetOutputSchema()->nullable(),
        ]);
    }

    public static function mediaListAssetsOutputSchema(): ZodObject
    {
        return z::object([
            'results' => z::array(self::mediaAssetOutputSchema()),
            'pagination' => z::object([
                'page' => z::number(),
                'pageSize' => z::number(),
                'pageCount' => z::number(),
                'total' => z::number(),
            ]),
        ]);
    }

    /** Folder tree node. `children` is recursive and unbounded in depth, so it is typed lazily. */
    public static function mediaFolderNodeSchema(): ZodType
    {
        return z::lazy(static fn (): ZodType => z::object([
            'id' => z::number(),
            'name' => z::string(),
            'children' => z::array(self::mediaFolderNodeSchema()),
        ]));
    }

    public static function mediaListFoldersOutputSchema(): ZodObject
    {
        return z::object([
            'data' => z::array(self::mediaFolderNodeSchema())->describe('Nested folder structure, roots first.'),
        ]);
    }

    /** `media_update_asset` output — the updated asset in the same shape the read tools return. */
    public static function mediaUpdateAssetOutputSchema(): ZodObject
    {
        return z::object([
            'data' => self::mediaAssetOutputSchema(),
        ]);
    }

    /** A folder as returned by the write tools (an ALLOWLIST: no `path` / `pathId`). */
    public static function mediaFolderOutputSchema(): ZodObject
    {
        return z::object([
            'id' => z::number()->describe('Numeric folder id — the canonical identifier for this folder.'),
            'name' => z::string(),
            'parent' => z::object([
                'id' => z::number(),
                'name' => z::string()->optional(),
            ])
                ->nullable()
                ->optional()
                ->describe('Containing folder, or null when the folder sits at the media library root. Absent when this response did not load the relation — absent means unknown, not root.'),
            'createdAt' => z::string()->nullable()->optional(),
            'updatedAt' => z::string()->nullable()->optional(),
        ]);
    }

    public static function mediaCreateFolderOutputSchema(): ZodObject
    {
        return z::object(['data' => self::mediaFolderOutputSchema()]);
    }

    public static function mediaRenameFolderOutputSchema(): ZodObject
    {
        return z::object(['data' => self::mediaFolderOutputSchema()]);
    }

    public static function mediaMoveFolderOutputSchema(): ZodObject
    {
        return z::object(['data' => self::mediaFolderOutputSchema()]);
    }

    /** `media_delete_folder` output — the same shape for both branches. */
    public static function mediaDeleteFolderOutputSchema(): ZodObject
    {
        return z::object([
            'dryRun' => z::boolean()->describe('True when this was a preview and NOTHING was deleted. False when the deletion was performed.'),
            'folders' => z::array(self::mediaFolderOutputSchema())->describe('The folders matched by the given ids (the roots of the cascade).'),
            'totalFolderNumber' => z::number()->describe('Total folders affected, including the matched folders themselves and every descendant.'),
            'totalFileNumber' => z::number()->describe('Total files affected, across the whole cascade.'),
        ]);
    }

    public static function mediaMoveAssetsFailureSchema(): ZodObject
    {
        return z::object([
            'id' => z::number()->describe('The requested asset id that was not moved.'),
            'reason' => z::string()->describe('Why this id was not moved — a missing asset, or one this token may not edit.'),
        ]);
    }

    /** `media_move_assets` output — a per-id report rather than a single verdict. */
    public static function mediaMoveAssetsOutputSchema(): ZodObject
    {
        return z::object([
            'destinationFolder' => z::object([
                'id' => z::number(),
                'name' => z::string()->optional(),
            ])
                ->nullable()
                ->describe('The destination folder, or null when the assets were moved to the media library root.'),
            'moved' => z::array(self::mediaAssetOutputSchema())->describe('The assets that were moved, in their new location.'),
            'failed' => z::array(self::mediaMoveAssetsFailureSchema())->describe('The ids that were not moved, each with a reason. The moves reported in `moved` still happened — retry only these.'),
        ]);
    }

    public static function mediaDeleteAssetsFailureSchema(): ZodObject
    {
        return z::object([
            'id' => z::number()->describe('The requested asset id that was not deleted.'),
            'reason' => z::string()->describe('Why this id was not deleted — a missing asset (possibly a folder id), or one this token may not delete.'),
        ]);
    }

    /** `media_delete_assets` output — one contract for both branches, with a per-id account. */
    public static function mediaDeleteAssetsOutputSchema(): ZodObject
    {
        return z::object([
            'dryRun' => z::boolean()->describe('True when this was a preview and NOTHING was deleted. False when the deletion was performed and is irreversible.'),
            'deleted' => z::array(self::mediaAssetOutputSchema())->describe('On a dry run, the assets that WOULD be permanently deleted. On a real run, the assets that were deleted — they no longer exist and cannot be read back.'),
            'failed' => z::array(self::mediaDeleteAssetsFailureSchema())->describe('The ids that were not deleted, each with a reason. On a real run the deletions reported in `deleted` still happened — retry only these.'),
            'totalFileNumber' => z::number()->describe('How many assets are in `deleted` — the count that WOULD be removed on a dry run, or that was removed on a real one.'),
        ]);
    }
}
