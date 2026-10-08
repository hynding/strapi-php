<?php

declare(strict_types=1);

namespace Strapi\Upload\Mcp\Handlers;

use Strapi\Core\Strapi;
use Strapi\Upload\Constants as UploadConstants;
use Strapi\Upload\Controllers\Utils\Folders;
use Strapi\Upload\Mcp\AmbientInstance;
use Strapi\Upload\Mcp\Permissions;
use Strapi\Upload\Mcp\Sanitizers\SanitizeMedia;
use Strapi\Upload\Mcp\Utils;
use Strapi\Utils\Errors\NotFoundError;
use Strapi\Utils\Errors\ValidationError;

/**
 * Port of server/src/mcp/handlers/folder-handlers.ts.
 *
 * @phpstan-import-type McpHandlerContext from \Strapi\Upload\Mcp\Types
 * @phpstan-import-type McpToolHandlerReturn from \Strapi\Upload\Mcp\Types
 */
final class FolderHandlers
{
    /**
     * `withParent` populates the parent relation in the same round trip.
     *
     * @return array<string, mixed>|null
     */
    private static function findFolderById(Strapi $strapi, mixed $id, bool $withParent = false): ?array
    {
        $row = $strapi->db()->query(UploadConstants::FOLDER_MODEL_UID)->findOne([
            'select' => ['id', 'name', 'path'],
            'where' => ['id' => $id],
            ...($withParent ? ['populate' => ['parent' => ['select' => ['id']]]] : []),
        ]);

        return is_array($row) ? $row : null;
    }

    /**
     * Re-reads a folder with its parent populated, so the write tools echo back the same shape.
     *
     * @param array<string, mixed> $fallback
     * @return array<string, mixed>|null
     */
    private static function readFolderForOutput(Strapi $strapi, mixed $id, array $fallback): ?array
    {
        $row = $strapi->db()->query(UploadConstants::FOLDER_MODEL_UID)->findOne([
            'where' => ['id' => $id],
            'populate' => ['parent' => ['select' => ['id', 'name']]],
        ]);

        return SanitizeMedia::sanitizeMediaFolder(is_array($row) ? $row : $fallback);
    }

    /** A named parent must exist; null is the media library root. */
    private static function assertParentExists(Strapi $strapi, mixed $parent): void
    {
        if ($parent === null) {
            return;
        }

        if (!AmbientInstance::getFolderService($strapi)->exists(['id' => $parent])) {
            throw new ValidationError(Constants::MCP_PARENT_FOLDER_NOT_FOUND);
        }
    }

    /** A folder name must be unique among its siblings (`excludeId`: the folder being renamed). */
    private static function assertNameAvailable(Strapi $strapi, mixed $name, mixed $parent, mixed $excludeId = null): void
    {
        $filters = ['name' => $name, 'parent' => $parent];

        if ($excludeId !== null) {
            $filters['id'] = ['$ne' => $excludeId];
        }

        if (AmbientInstance::getFolderService($strapi)->exists($filters)) {
            throw new ValidationError(Constants::MCP_FOLDER_NAME_TAKEN);
        }
    }

    /**
     * A folder cannot become its own descendant.
     *
     * @param array<string, mixed> $folder
     */
    private static function assertNotMovedIntoOwnSubtree(Strapi $strapi, array $folder, mixed $parent): void
    {
        if ($parent === null) {
            return;
        }

        $destination = self::findFolderById($strapi, $parent);

        // A missing destination is already reported by `assertParentExists`.
        if ($destination === null) {
            return;
        }

        if (Folders::isFolderOrChild(['path' => (string) $destination['path']], ['path' => (string) $folder['path']])) {
            throw new ValidationError(Constants::MCP_FOLDER_MOVE_INTO_SELF);
        }
    }

    /**
     * Counts the folders and files a `deleteByIds` of these paths would remove, with the same
     * predicates `folder.deleteByIds` uses.
     *
     * @param list<string> $paths
     * @return array{totalFolderNumber: int, totalFileNumber: int}
     */
    private static function countCascade(Strapi $strapi, array $paths): array
    {
        $pathPredicate = static fn (string $field): array => [
            '$or' => array_merge(...array_map(static fn (string $path): array => [
                [$field => ['$eq' => $path]],
                [$field => ['$startsWith' => "{$path}/"]],
            ], $paths)),
        ];

        return [
            'totalFolderNumber' => (int) $strapi->db()->query(UploadConstants::FOLDER_MODEL_UID)->count(['where' => $pathPredicate('path')]),
            'totalFileNumber' => (int) $strapi->db()->query(UploadConstants::FILE_MODEL_UID)->count(['where' => $pathPredicate('folderPath')]),
        ];
    }

    /**
     * `media_create_folder` — a new folder, optionally nested (as `POST /upload/folders`).
     *
     * @param McpHandlerContext $context
     * @return \Closure(array{args?: array<string, mixed>}): McpToolHandlerReturn
     */
    public static function createMediaCreateFolderHandler(Strapi $strapi, array $context): \Closure
    {
        AmbientInstance::assertAmbientInstance($strapi);

        return static function (array $params) use ($strapi, $context): array {
            $args = $params['args'] ?? [];
            $name = $args['name'] ?? null;
            $parent = $args['parent'] ?? null;

            Permissions::assertMediaPermission($strapi, $context, UploadConstants::ACTIONS['create'], UploadConstants::FOLDER_MODEL_UID);

            self::assertParentExists($strapi, $parent);
            self::assertNameAvailable($strapi, $name, $parent);

            $created = AmbientInstance::getFolderService($strapi)->create(['name' => $name, 'parent' => $parent], ['user' => $context['user'] ?? null]);

            return Utils::ok(['data' => self::readFolderForOutput($strapi, $created['id'] ?? null, $created)]);
        };
    }

    /**
     * `media_rename_folder` — changes a folder's name and nothing else (no `parent` is forwarded,
     * so the service skips the transaction that rewrites descendant paths).
     *
     * @param McpHandlerContext $context
     * @return \Closure(array{args?: array<string, mixed>}): McpToolHandlerReturn
     */
    public static function createMediaRenameFolderHandler(Strapi $strapi, array $context): \Closure
    {
        AmbientInstance::assertAmbientInstance($strapi);

        return static function (array $params) use ($strapi, $context): array {
            $args = $params['args'] ?? [];
            $id = $args['id'] ?? 0;
            $name = $args['name'] ?? null;

            Permissions::assertMediaPermission($strapi, $context, UploadConstants::ACTIONS['update'], UploadConstants::FOLDER_MODEL_UID);

            $folder = self::findFolderById($strapi, $id, true);

            if ($folder === null) {
                throw new NotFoundError(Constants::MCP_NOT_FOUND_FOLDER);
            }

            // Siblings are the folders sharing this folder's parent
            $parentId = is_array($folder['parent'] ?? null) ? ($folder['parent']['id'] ?? null) : null;

            self::assertNameAvailable($strapi, $name, $parentId, $id);

            $renamed = AmbientInstance::getFolderService($strapi)->update(is_int($id) || is_string($id) ? $id : 0, ['name' => $name], ['user' => $context['user'] ?? null]);

            return Utils::ok(['data' => self::readFolderForOutput($strapi, $id, $renamed ?? [...$folder, 'name' => $name])]);
        };
    }

    /**
     * `media_move_folder` — re-parents a folder, carrying its whole subtree with it.
     *
     * @param McpHandlerContext $context
     * @return \Closure(array{args?: array<string, mixed>}): McpToolHandlerReturn
     */
    public static function createMediaMoveFolderHandler(Strapi $strapi, array $context): \Closure
    {
        AmbientInstance::assertAmbientInstance($strapi);

        return static function (array $params) use ($strapi, $context): array {
            $args = $params['args'] ?? [];
            $id = $args['id'] ?? 0;
            $parent = $args['parent'] ?? null;

            Permissions::assertMediaPermission($strapi, $context, UploadConstants::ACTIONS['update'], UploadConstants::FOLDER_MODEL_UID);

            $folder = self::findFolderById($strapi, $id);

            if ($folder === null) {
                throw new NotFoundError(Constants::MCP_NOT_FOUND_FOLDER);
            }

            self::assertParentExists($strapi, $parent);
            self::assertNotMovedIntoOwnSubtree($strapi, $folder, $parent);

            // A folder keeps its name across a move, so uniqueness must hold in the destination.
            self::assertNameAvailable($strapi, $folder['name'] ?? null, $parent, $id);

            $moved = AmbientInstance::getFolderService($strapi)->update(
                is_int($id) || is_string($id) ? $id : 0,
                ['name' => $folder['name'] ?? null, 'parent' => $parent],
                ['user' => $context['user'] ?? null],
            );

            return Utils::ok(['data' => self::readFolderForOutput($strapi, $id, $moved ?? $folder)]);
        };
    }

    /**
     * `media_delete_folder` — previews (`dryRun`, the default) or performs a cascading folder
     * deletion. Any id that does not resolve to a folder rejects the whole call.
     *
     * @param McpHandlerContext $context
     * @return \Closure(array{args?: array<string, mixed>}): McpToolHandlerReturn
     */
    public static function createMediaDeleteFolderHandler(Strapi $strapi, array $context): \Closure
    {
        AmbientInstance::assertAmbientInstance($strapi);

        return static function (array $params) use ($strapi, $context): array {
            $args = $params['args'] ?? [];
            $ids = is_array($args['ids'] ?? null) ? array_values($args['ids']) : [];
            $dryRun = ($args['dryRun'] ?? true) !== false;

            Permissions::assertMediaPermission($strapi, $context, UploadConstants::ACTIONS['update'], UploadConstants::FOLDER_MODEL_UID);

            $matched = array_values($strapi->db()->query(UploadConstants::FOLDER_MODEL_UID)->findMany([
                'select' => ['id', 'name', 'path'],
                'where' => ['id' => ['$in' => $ids]],
            ]));

            $matchedIds = array_map(static fn (array $folder): int => (int) $folder['id'], $matched);
            $unresolvedIds = array_values(array_filter(
                array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids),
                static fn (int $id): bool => !in_array($id, $matchedIds, true),
            ));

            if ($unresolvedIds !== []) {
                throw new ValidationError(Constants::MCP_DELETE_FOLDER_UNRESOLVED_IDS($unresolvedIds));
            }

            $folders = array_map(static fn (array $folder): ?array => SanitizeMedia::sanitizeMediaFolder($folder), $matched);

            if ($dryRun) {
                $counts = self::countCascade($strapi, array_map(static fn (array $folder): string => (string) $folder['path'], $matched));

                return Utils::ok(['dryRun' => true, 'folders' => $folders, ...$counts]);
            }

            [
                'totalFolderNumber' => $totalFolderNumber,
                'totalFileNumber' => $totalFileNumber,
            ] = AmbientInstance::getFolderService($strapi)->deleteByIds($matchedIds);

            return Utils::ok([
                'dryRun' => false,
                'folders' => $folders,
                'totalFolderNumber' => $totalFolderNumber,
                'totalFileNumber' => $totalFileNumber,
            ]);
        };
    }
}
