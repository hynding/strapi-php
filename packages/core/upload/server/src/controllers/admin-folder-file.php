<?php

declare(strict_types=1);

namespace Strapi\Upload\Controllers;

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Upload\Constants;
use Strapi\Upload\Controllers\Validation\Admin\FolderFile as FolderFileValidation;
use Strapi\Upload\FolderContainsUnauthorizedAssetsError;
use Strapi\Upload\Utils\Utils;
use Strapi\Utils\Errors\ForbiddenError;
use Strapi\Utils\Primitives\Strings;

/** Port of server/src/controllers/admin-folder-file.ts. */
final class AdminFolderFile
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function deleteMany(Context $ctx): void
    {
        $body = $ctx->requestBody();
        $userAbility = $ctx->state()->get('userAbility');

        $pmFolder = Utils::permissionService($this->strapi)->createPermissionsManager([
            'ability' => $userAbility,
            'model' => Constants::FOLDER_MODEL_UID,
        ]);

        $pmFile = Utils::permissionService($this->strapi)->createPermissionsManager([
            'ability' => $userAbility,
            'action' => Constants::ACTIONS['update'],
            'model' => Constants::FILE_MODEL_UID,
        ]);

        FolderFileValidation::validateDeleteManyFoldersFiles($body);

        $fileService = Utils::getService('file', $this->strapi);
        $folderService = Utils::getService('folder', $this->strapi);

        $fileIds = is_array($body['fileIds'] ?? null) ? array_values($body['fileIds']) : [];
        $folderIds = is_array($body['folderIds'] ?? null) ? array_values($body['folderIds']) : [];

        $canDeleteFiles = function (array $files) use ($pmFile): bool {
            $ids = array_values(array_unique(array_map(static fn (array $file): mixed => $file['id'], $files)));

            if ($ids === []) {
                return true;
            }

            $query = $pmFile->addPermissionsQueryTo(['filters' => ['id' => ['$in' => $ids]]]);
            $permittedFiles = $this->strapi->db()->query(Constants::FILE_MODEL_UID)->findMany([
                'select' => ['id'],
                'where' => $query['filters'] ?? [],
            ]);

            return count($permittedFiles) === count($ids);
        };

        // https://github.com/strapi/strapi/issues/27649
        // Validate explicitly selected files before deleting a folder. Otherwise a mixed request
        // could delete permitted files first and only then discover a forbidden file in the folder.
        if ($fileIds !== []) {
            $selectedFiles = $this->strapi->db()->query(Constants::FILE_MODEL_UID)->findMany([
                'where' => ['id' => ['$in' => $fileIds]],
            ]);

            if (!$canDeleteFiles($selectedFiles)) {
                throw new ForbiddenError();
            }
        }

        [
            'folders' => $deletedFolders,
            'totalFolderNumber' => $totalFolderNumber,
            'totalFileNumber' => $totalFileNumber,
        ] = $folderService->deleteByIds($folderIds, [
            'validateFiles' => static function (array $files) use ($canDeleteFiles): void {
                if (!$canDeleteFiles($files)) {
                    throw new FolderContainsUnauthorizedAssetsError();
                }
            },
        ]);
        $deletedFiles = $fileService->deleteByIds($fileIds);

        if (count($deletedFiles) + count($deletedFolders) > 1) {
            Utils::getService('metrics', $this->strapi)->trackUsage('didBulkDeleteMediaLibraryElements', [
                'eventProperties' => [
                    'rootFolderNumber' => count($deletedFolders),
                    'rootAssetNumber' => count($deletedFiles),
                    'totalFolderNumber' => $totalFolderNumber,
                    'totalAssetNumber' => $totalFileNumber + count($deletedFiles),
                ],
            ]);
        }

        $ctx->setBody([
            'data' => [
                'files' => $pmFile->sanitizeOutput($deletedFiles, ['action' => Constants::ACTIONS['read']]),
                'folders' => $pmFolder->sanitizeOutput($deletedFolders),
            ],
        ]);
    }

    public function moveMany(Context $ctx): void
    {
        $body = $ctx->requestBody();
        $userAbility = $ctx->state()->get('userAbility');

        $pmFolder = Utils::permissionService($this->strapi)->createPermissionsManager([
            'ability' => $userAbility,
            'model' => Constants::FOLDER_MODEL_UID,
        ]);

        $pmFile = Utils::permissionService($this->strapi)->createPermissionsManager([
            'ability' => $userAbility,
            'action' => Constants::ACTIONS['read'],
            'model' => Constants::FILE_MODEL_UID,
        ]);

        FolderFileValidation::validateMoveManyFoldersFiles($this->strapi, $body);
        $folderIds = is_array($body['folderIds'] ?? null) ? array_values($body['folderIds']) : [];
        $fileIds = is_array($body['fileIds'] ?? null) ? array_values($body['fileIds']) : [];
        $destinationFolderId = $body['destinationFolderId'] ?? null;

        $totalFolderNumber = 0;
        $totalFileNumber = 0;

        $db = $this->strapi->db();

        $db->transaction(function () use ($db, $folderIds, $fileIds, $destinationFolderId, &$totalFolderNumber, &$totalFileNumber): void {
            // fetch folders
            $existingFolders = $db->queryBuilder(Constants::FOLDER_MODEL_UID)
                ->select(['id', 'pathId', 'path'])
                ->where(['id' => ['$in' => $folderIds]])
                ->forUpdate()
                ->execute();
            $existingFolders = is_array($existingFolders) ? $existingFolders : [];

            // fetch files
            $existingFiles = $db->queryBuilder(Constants::FILE_MODEL_UID)
                ->select(['id'])
                ->where(['id' => ['$in' => $fileIds]])
                ->forUpdate()
                ->execute();
            $existingFiles = is_array($existingFiles) ? $existingFiles : [];

            // fetch destinationFolder path
            $destinationFolderPath = '/';
            if ($destinationFolderId !== null) {
                $destinationFolder = $db->queryBuilder(Constants::FOLDER_MODEL_UID)
                    ->select('path')
                    ->where(['id' => $destinationFolderId])
                    ->first()
                    ->execute();
                $destinationFolderPath = is_array($destinationFolder) ? (string) $destinationFolder['path'] : '/';
            }

            $fileTable = $db->metadata(Constants::FILE_MODEL_UID)['tableName'];
            $folderTable = $db->metadata(Constants::FOLDER_MODEL_UID)['tableName'];
            $folderPathColName = $db->metadata(Constants::FILE_MODEL_UID)['attributes']['folderPath']['columnName'];
            $pathColName = $db->metadata(Constants::FOLDER_MODEL_UID)['attributes']['path']['columnName'];

            if ($existingFolders !== []) {
                // update folders' parent relation
                $joinTable = $db->metadata(Constants::FOLDER_MODEL_UID)['attributes']['parent']['joinTable'];
                $db->sql()->from($joinTable['name'])
                    ->whereIn($joinTable['joinColumn']['name'], $folderIds)
                    ->delete()
                    ->run();

                if ($destinationFolderId !== null) {
                    $db->sql()->from($joinTable['name'])
                        ->insert(array_map(static fn (array $folder): array => [
                            $joinTable['inverseJoinColumn']['name'] => $destinationFolderId,
                            $joinTable['joinColumn']['name'] => $folder['id'],
                        ], array_values($existingFolders)))
                        ->run();
                }

                foreach ($existingFolders as $existingFolder) {
                    $replaceQuery = match ($db->dialect->client) {
                        'sqlite' => '? || SUBSTR(%s, ?)',
                        'postgres' => 'CONCAT(?::TEXT, SUBSTRING(%s, ?::INTEGER))',
                        default => 'CONCAT(?, SUBSTRING(%s, ?))',
                    };
                    $newPathPrefix = Strings::joinBy('/', $destinationFolderPath, (string) $existingFolder['pathId']);
                    $existingPath = (string) $existingFolder['path'];

                    // update path for folders themselves & folders below
                    $sql = $db->sql();
                    $totalFolderNumber = (int) $sql->from($folderTable)
                        ->where($pathColName, $existingPath)
                        ->orWhere($pathColName, 'like', "{$existingPath}/%")
                        ->update([$pathColName => $sql->raw(sprintf($replaceQuery, $sql->quoteIdentifier($pathColName)), [$newPathPrefix, strlen($existingPath) + 1])])
                        ->run();

                    // update path of files below
                    $sql = $db->sql();
                    $totalFileNumber = (int) $sql->from($fileTable)
                        ->where($folderPathColName, $existingPath)
                        ->orWhere($folderPathColName, 'like', "{$existingPath}/%")
                        ->update([$folderPathColName => $sql->raw(sprintf($replaceQuery, $sql->quoteIdentifier($folderPathColName)), [$newPathPrefix, strlen($existingPath) + 1])])
                        ->run();
                }
            }

            if ($existingFiles !== []) {
                // update files' folder relation (delete + insert; upsert not possible)
                $fileJoinTable = $db->metadata(Constants::FILE_MODEL_UID)['attributes']['folder']['joinTable'];
                $db->sql()->from($fileJoinTable['name'])
                    ->whereIn($fileJoinTable['joinColumn']['name'], $fileIds)
                    ->delete()
                    ->run();

                if ($destinationFolderId !== null) {
                    $db->sql()->from($fileJoinTable['name'])
                        ->insert(array_map(static fn (array $file): array => [
                            $fileJoinTable['inverseJoinColumn']['name'] => $destinationFolderId,
                            $fileJoinTable['joinColumn']['name'] => $file['id'],
                        ], array_values($existingFiles)))
                        ->run();
                }

                // update files main fields (path + updatedBy)
                $db->sql()->from($fileTable)
                    ->whereIn('id', $fileIds)
                    ->update([$folderPathColName => $destinationFolderPath])
                    ->run();
            }
        });

        $updatedFolders = $db->query(Constants::FOLDER_MODEL_UID)->findMany([
            'where' => ['id' => ['$in' => $folderIds]],
        ]);

        $updatedFiles = $db->query(Constants::FILE_MODEL_UID)->findMany([
            'where' => ['id' => ['$in' => $fileIds]],
        ]);

        Utils::getService('metrics', $this->strapi)->trackUsage('didBulkMoveMediaLibraryElements', [
            'eventProperties' => [
                'rootFolderNumber' => count($updatedFolders),
                'rootAssetNumber' => count($updatedFiles),
                'totalFolderNumber' => $totalFolderNumber,
                'totalAssetNumber' => $totalFileNumber + count($updatedFiles),
            ],
        ]);

        $ctx->setBody([
            'data' => [
                'files' => $pmFile->sanitizeOutput(array_values($updatedFiles)),
                'folders' => $pmFolder->sanitizeOutput(array_values($updatedFolders)),
            ],
        ]);
    }
}
