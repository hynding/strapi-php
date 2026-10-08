<?php

declare(strict_types=1);

namespace Strapi\Upload\Mcp\Handlers;

use Strapi\Core\Strapi;
use Strapi\Upload\Constants as UploadConstants;
use Strapi\Upload\Controllers\Utils\FindEntityAndCheckPermissions;
use Strapi\Upload\Mcp\AmbientInstance;
use Strapi\Upload\Mcp\Permissions;
use Strapi\Upload\Mcp\Sanitizers\SanitizeMedia;
use Strapi\Upload\Mcp\Utils;
use Strapi\Upload\Utils\Utils as UploadUtils;
use Strapi\Utils\Errors\NotFoundError;

/**
 * Port of server/src/mcp/handlers/read-handlers.ts.
 *
 * @phpstan-import-type McpHandlerContext from \Strapi\Upload\Mcp\Types
 * @phpstan-import-type McpToolHandlerReturn from \Strapi\Upload\Mcp\Types
 */
final class ReadHandlers
{
    private const string DEFAULT_SORT = 'createdAt:DESC';

    /**
     * Builds the `filters` clause for `media_list_assets`.
     *
     * `folderId: null` means "assets at the media library root"; omitting it means "any folder".
     * `mime` without a slash is matched as a prefix ("image" → every "image/*").
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private static function buildAssetFilters(array $args): array
    {
        $filters = [];

        if (array_key_exists('folderId', $args) && $args['folderId'] === null) {
            $filters['folder'] = null;
        } elseif (isset($args['folderId'])) {
            $filters['folder'] = ['id' => $args['folderId']];
        }

        if (isset($args['mime']) && is_string($args['mime'])) {
            $filters['mime'] = str_contains($args['mime'], '/')
                ? ['$eqi' => $args['mime']]
                : ['$startsWithi' => "{$args['mime']}/"];
        }

        if (isset($args['name'])) {
            $filters['name'] = ['$containsi' => $args['name']];
        }

        return $filters;
    }

    /**
     * `media_list_assets` — paginated, filtered listing of media files, with the permission
     * conditions applied through `addPermissionsQueryTo`.
     *
     * @param McpHandlerContext $context
     * @return \Closure(array{args?: array<string, mixed>}): McpToolHandlerReturn
     */
    public static function createMediaListAssetsHandler(Strapi $strapi, array $context): \Closure
    {
        AmbientInstance::assertAmbientInstance($strapi);

        return static function (array $params) use ($strapi, $context): array {
            $args = $params['args'] ?? [];
            $pm = Permissions::assertMediaPermission($strapi, $context, UploadConstants::ACTIONS['read'], UploadConstants::FILE_MODEL_UID);

            $query = $pm->addPermissionsQueryTo([
                'filters' => self::buildAssetFilters($args),
                'sort' => $args['sort'] ?? self::DEFAULT_SORT,
                'page' => $args['page'] ?? 1,
                'pageSize' => $args['pageSize'] ?? 25,
                'populate' => ['folder' => ['fields' => ['id', 'name']]],
            ]);

            ['results' => $results, 'pagination' => $pagination] = UploadUtils::getService('upload', $strapi)->findPage($query);

            return Utils::ok([
                'results' => array_map(SanitizeMedia::sanitizeMediaAsset(...), $results),
                'pagination' => $pagination,
            ]);
        };
    }

    /**
     * `media_get_asset` — a single asset by numeric id, with the row-level permission conditions
     * enforced by `findEntityAndCheckPermissions`.
     *
     * @param McpHandlerContext $context
     * @return \Closure(array{args?: array<string, mixed>}): McpToolHandlerReturn
     */
    public static function createMediaGetAssetHandler(Strapi $strapi, array $context): \Closure
    {
        AmbientInstance::assertAmbientInstance($strapi);

        return static function (array $params) use ($strapi, $context): array {
            $id = $params['args']['id'] ?? 0;

            // Model-level gate first, so a token without upload read at all is refused before a lookup.
            Permissions::assertMediaPermission($strapi, $context, UploadConstants::ACTIONS['read'], UploadConstants::FILE_MODEL_UID);

            try {
                ['file' => $asset] = FindEntityAndCheckPermissions::findEntityAndCheckPermissions(
                    $strapi,
                    $context['userAbility'],
                    UploadConstants::ACTIONS['read'],
                    UploadConstants::FILE_MODEL_UID,
                    is_int($id) || is_string($id) ? $id : 0,
                );
            } catch (NotFoundError) {
                // MCP answers an agent: restate it with the message the other media tools use.
                throw new NotFoundError(Constants::MCP_NOT_FOUND_ASSET);
            }

            return Utils::ok(['data' => SanitizeMedia::sanitizeMediaAsset($asset)]);
        };
    }

    /**
     * `media_list_folders` — the nested folder structure, gated on the model-level read permission
     * only (as `GET /upload/folder-structure`).
     *
     * @param McpHandlerContext $context
     * @return \Closure(): McpToolHandlerReturn
     */
    public static function createMediaListFoldersHandler(Strapi $strapi, array $context): \Closure
    {
        AmbientInstance::assertAmbientInstance($strapi);

        return static function () use ($strapi, $context): array {
            Permissions::assertMediaPermission($strapi, $context, UploadConstants::ACTIONS['read'], UploadConstants::FOLDER_MODEL_UID);

            $structure = AmbientInstance::getFolderService($strapi)->getStructure();

            return Utils::ok(['data' => SanitizeMedia::sanitizeMediaFolderTree($structure)]);
        };
    }
}
