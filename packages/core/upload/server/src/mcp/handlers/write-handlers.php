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
use Strapi\Utils\Errors\ForbiddenError;
use Strapi\Utils\Errors\NotFoundError;
use Strapi\Utils\Errors\ValidationError;

/**
 * Port of server/src/mcp/handlers/write-handlers.ts.
 *
 * @phpstan-import-type McpHandlerContext from \Strapi\Upload\Mcp\Types
 * @phpstan-import-type McpToolHandlerReturn from \Strapi\Upload\Mcp\Types
 */
final class WriteHandlers
{
    /** The metadata keys `media_update_asset` may write. Everything else is rejected by the schema. */
    private const array WRITABLE_FIELDS = ['name', 'alternativeText', 'caption'];

    /**
     * Picks the metadata the caller actually sent. `updateFileInfo` treats nil as "keep the stored
     * value", so clearing is expressed as an empty string, as the admin panel does.
     *
     * @param array<string, mixed> $args
     * @return array<string, string>
     */
    private static function buildFileInfo(array $args): array
    {
        $fileInfo = [];

        foreach (self::WRITABLE_FIELDS as $field) {
            if (array_key_exists($field, $args)) {
                $value = $args[$field];
                $fileInfo[$field] = $value === null ? '' : (is_scalar($value) ? (string) $value : '');
            }
        }

        return $fileInfo;
    }

    /**
     * @param McpHandlerContext $context
     * @return array{pm: \Strapi\Admin\Services\Permission\PermissionsManager\PermissionsManager, file: array<string, mixed>}
     */
    private static function assertRow(Strapi $strapi, array $context, mixed $id): array
    {
        return FindEntityAndCheckPermissions::findEntityAndCheckPermissions(
            $strapi,
            $context['userAbility'],
            UploadConstants::ACTIONS['update'],
            UploadConstants::FILE_MODEL_UID,
            is_int($id) || is_string($id) ? $id : 0,
        );
    }

    /**
     * `media_update_asset` — edits the writable metadata of one asset, as `PUT /upload/files/:id`.
     *
     * @param McpHandlerContext $context
     * @return \Closure(array{args?: array<string, mixed>}): McpToolHandlerReturn
     */
    public static function createMediaUpdateAssetHandler(Strapi $strapi, array $context): \Closure
    {
        AmbientInstance::assertAmbientInstance($strapi);

        return static function (array $params) use ($strapi, $context): array {
            $args = $params['args'] ?? [];
            $id = $args['id'] ?? 0;
            unset($args['id']);
            $fileInfo = self::buildFileInfo($args);

            // A patch with no writable field is a caller error, not a no-op success.
            if ($fileInfo === []) {
                throw new ValidationError(Constants::MCP_UPDATE_ASSET_NO_FIELDS);
            }

            // Model-level gate first, so a token without the action is refused before any DB read.
            Permissions::assertMediaPermission($strapi, $context, UploadConstants::ACTIONS['update'], UploadConstants::FILE_MODEL_UID);

            try {
                self::assertRow($strapi, $context, $id);
            } catch (NotFoundError) {
                throw new NotFoundError(Constants::MCP_NOT_FOUND_ASSET);
            }

            $uploadService = UploadUtils::getService('upload', $strapi);
            $written = $uploadService->updateFileInfo(is_int($id) || is_string($id) ? $id : 0, $fileInfo, ['user' => $context['user'] ?? null]);

            $updated = $uploadService->findOne(is_int($id) || is_string($id) ? $id : 0, ['folder']);

            return Utils::ok(['data' => SanitizeMedia::sanitizeMediaAsset($updated ?? $written)]);
        };
    }

    /**
     * Resolves the destination folder for `media_move_assets`, and rejects one that does not exist.
     *
     * @return array{id: int, name: string}|null
     */
    private static function resolveDestinationFolder(Strapi $strapi, mixed $folder): ?array
    {
        if ($folder === null) {
            return null;
        }

        $destination = $strapi->db()->query(UploadConstants::FOLDER_MODEL_UID)->findOne([
            'select' => ['id', 'name'],
            'where' => ['id' => $folder],
        ]);

        if (!is_array($destination)) {
            throw new ValidationError(Constants::MCP_MOVE_ASSETS_DESTINATION_NOT_FOUND);
        }

        return ['id' => (int) $destination['id'], 'name' => (string) ($destination['name'] ?? '')];
    }

    /**
     * `media_move_assets` — moves assets between folders in bulk, one asset at a time, with a
     * per-id report (see upstream for the rationale).
     *
     * @param McpHandlerContext $context
     * @return \Closure(array{args?: array<string, mixed>}): McpToolHandlerReturn
     */
    public static function createMediaMoveAssetsHandler(Strapi $strapi, array $context): \Closure
    {
        AmbientInstance::assertAmbientInstance($strapi);

        return static function (array $params) use ($strapi, $context): array {
            $args = $params['args'] ?? [];
            $ids = is_array($args['ids'] ?? null) ? $args['ids'] : [];
            $folder = $args['folder'] ?? null;

            // Model-level gate first, so a token without the action is refused before any DB read.
            Permissions::assertMediaPermission($strapi, $context, UploadConstants::ACTIONS['update'], UploadConstants::FILE_MODEL_UID);

            $destinationFolder = self::resolveDestinationFolder($strapi, $folder);

            $moved = [];
            $failed = [];

            // `ids` can repeat an id; de-duplicating keeps the report one entry per id.
            foreach (array_values(array_unique($ids, SORT_REGULAR)) as $id) {
                try {
                    self::assertRow($strapi, $context, $id);
                    $updated = UploadUtils::getService('upload', $strapi)->updateFileInfo(
                        is_int($id) || is_string($id) ? $id : 0,
                        // null is the root: forwarded as-is
                        ['folder' => $folder],
                        ['user' => $context['user'] ?? null],
                    );

                    // the destination is attached here instead of costing a read-back per asset
                    $moved[] = SanitizeMedia::sanitizeMediaAsset([...$updated, 'folder' => $destinationFolder]);
                } catch (NotFoundError) {
                    $failed[] = ['id' => $id, 'reason' => Constants::MCP_MOVE_ASSETS_ID_NOT_FOUND];
                } catch (ForbiddenError) {
                    $failed[] = ['id' => $id, 'reason' => Constants::MCP_MOVE_ASSETS_ID_FORBIDDEN];
                } catch (\Throwable $error) {
                    // Reported per id rather than thrown, so the report of what moved is kept.
                    $failed[] = ['id' => $id, 'reason' => Constants::MCP_MOVE_ASSETS_ID_FAILED($error->getMessage())];
                }
            }

            return Utils::ok(['destinationFolder' => $destinationFolder, 'moved' => $moved, 'failed' => $failed]);
        };
    }

    /**
     * `media_delete_assets` — previews (`dryRun`, the default) or performs the permanent deletion
     * of assets, per id.
     *
     * @param McpHandlerContext $context
     * @return \Closure(array{args?: array<string, mixed>}): McpToolHandlerReturn
     */
    public static function createMediaDeleteAssetsHandler(Strapi $strapi, array $context): \Closure
    {
        AmbientInstance::assertAmbientInstance($strapi);

        return static function (array $params) use ($strapi, $context): array {
            $args = $params['args'] ?? [];
            $ids = is_array($args['ids'] ?? null) ? $args['ids'] : [];
            $dryRun = ($args['dryRun'] ?? true) !== false;

            // Model-level gate first; the preview takes the same gate.
            Permissions::assertMediaPermission($strapi, $context, UploadConstants::ACTIONS['update'], UploadConstants::FILE_MODEL_UID);

            $deleted = [];
            $failed = [];

            foreach (array_values(array_unique($ids, SORT_REGULAR)) as $id) {
                try {
                    ['file' => $file] = self::assertRow($strapi, $context, $id);
                } catch (NotFoundError) {
                    $failed[] = ['id' => $id, 'reason' => Constants::MCP_DELETE_ASSETS_ID_NOT_FOUND];
                    continue;
                } catch (ForbiddenError) {
                    $failed[] = ['id' => $id, 'reason' => Constants::MCP_DELETE_ASSETS_ID_FORBIDDEN];
                    continue;
                } catch (\Throwable $error) {
                    $failed[] = ['id' => $id, 'reason' => Constants::MCP_DELETE_ASSETS_ID_FAILED($error->getMessage())];
                    continue;
                }

                // The preview stops here, reported in the same shape the real run uses.
                if ($dryRun) {
                    $deleted[] = SanitizeMedia::sanitizeMediaAsset($file);
                    continue;
                }

                try {
                    UploadUtils::getService('upload', $strapi)->remove($file);

                    // Reported from the row read before the delete
                    $deleted[] = SanitizeMedia::sanitizeMediaAsset($file);
                } catch (\Throwable $error) {
                    $failed[] = ['id' => $id, 'reason' => Constants::MCP_DELETE_ASSETS_ID_FAILED($error->getMessage())];
                }
            }

            return Utils::ok(['dryRun' => $dryRun, 'deleted' => $deleted, 'failed' => $failed, 'totalFileNumber' => count($deleted)]);
        };
    }
}
