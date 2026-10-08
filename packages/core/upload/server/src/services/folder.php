<?php

declare(strict_types=1);

namespace Strapi\Upload\Services;

use Strapi\Core\Strapi;
use Strapi\Upload\Constants;
use Strapi\Upload\Utils\Utils;
use Strapi\Utils\Primitives\Strings;
use Strapi\Utils\SetCreatorFields;

/**
 * Port of server/src/services/folder.ts. The raw knex statements (`getConnection(table)…update(raw)`)
 * run on the database's SQL builder in the same transaction.
 *
 * @phpstan-type DeleteByIdsOptions array{validateFiles?: callable(list<array<string, mixed>>): void}
 */
final class Folder
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * @param array<string, mixed> $folder
     * @return array<string, mixed>
     */
    public function setPathIdAndPath(array $folder): array
    {
        $result = $this->strapi->db()->queryBuilder(Constants::FOLDER_MODEL_UID)
            ->max('pathId')
            ->first()
            ->execute();
        $max = is_array($result) ? (int) ($result['max'] ?? 0) : 0;

        $pathId = $max + 1;
        $parentPath = '/';
        if (!empty($folder['parent'])) {
            $parentFolder = $this->strapi->db()->query(Constants::FOLDER_MODEL_UID)->findOne(['where' => ['id' => $folder['parent']]]);

            $parentPath = is_array($parentFolder) ? (string) $parentFolder['path'] : '/';
        }

        return [
            ...$folder,
            'pathId' => $pathId,
            'path' => Strings::joinBy('/', $parentPath, (string) $pathId),
        ];
    }

    /**
     * @param array<string, mixed> $folderData
     * @param array{user?: mixed} $opts
     * @return array<string, mixed>
     */
    public function create(array $folderData, array $opts = []): array
    {
        $folderService = Utils::getService('folder', $this->strapi);

        $user = $opts['user'] ?? null;

        $enrichedFolder = $folderService->setPathIdAndPath($folderData);
        if ($user) {
            $enrichedFolder = SetCreatorFields::apply($enrichedFolder, ['user' => $user]);
        }

        $folder = $this->strapi->db()->query(Constants::FOLDER_MODEL_UID)->create(['data' => $enrichedFolder]);

        $this->strapi->eventHub()->emit('media-folder.create', ['folder' => $folder]);

        return $folder;
    }

    /**
     * Recursively delete folders and included files
     *
     * @param list<int|string> $ids ids of the folders to delete
     * @param DeleteByIdsOptions $options
     * @return array{folders: list<array<string, mixed>>, totalFolderNumber: int, totalFileNumber: int}
     */
    public function deleteByIds(array $ids = [], array $options = []): array
    {
        $folders = $this->strapi->db()->query(Constants::FOLDER_MODEL_UID)->findMany(['where' => ['id' => ['$in' => $ids]]]);
        if ($folders === []) {
            return [
                'folders' => [],
                'totalFolderNumber' => 0,
                'totalFileNumber' => 0,
            ];
        }

        $pathsToDelete = array_map(static fn (array $folder): string => (string) $folder['path'], $folders);

        // delete files
        $filesToDelete = $this->strapi->db()->query(Constants::FILE_MODEL_UID)->findMany([
            'where' => [
                '$or' => array_merge(...array_map(static fn (string $path): array => [
                    ['folderPath' => ['$eq' => $path]],
                    ['folderPath' => ['$startsWith' => "{$path}/"]],
                ], $pathsToDelete)),
            ],
        ]);

        // A folder deletion cascades to every file in its subtree. Let callers enforce
        // request-scoped permissions against the complete set before the first destructive action.
        $validateFiles = $options['validateFiles'] ?? null;
        if ($validateFiles !== null) {
            $validateFiles(array_values($filesToDelete));
        }

        foreach ($filesToDelete as $file) {
            Utils::getService('upload', $this->strapi)->remove($file);
        }

        // delete folders and subfolders
        $deleted = $this->strapi->db()->query(Constants::FOLDER_MODEL_UID)->deleteMany([
            'where' => [
                '$or' => array_merge(...array_map(static fn (string $path): array => [
                    ['path' => ['$eq' => $path]],
                    ['path' => ['$startsWith' => "{$path}/"]],
                ], $pathsToDelete)),
            ],
        ]);

        $this->strapi->eventHub()->emit('media-folder.delete', ['folders' => $folders]);

        return [
            'folders' => array_values($folders),
            'totalFolderNumber' => (int) $deleted['count'],
            'totalFileNumber' => count($filesToDelete),
        ];
    }

    /**
     * Update name and location of a folder and its belonging folders and files
     *
     * @param array{name?: mixed, parent?: mixed} $data omit `parent` to rename in place: the
     *                                                 name-only branch skips the transaction that
     *                                                 rewrites descendant paths. A number re-parents,
     *                                                 null moves to the root.
     * @param array{user?: mixed} $opts
     * @return array<string, mixed>|null
     */
    public function update(int|string $id, array $data, array $opts = []): ?array
    {
        $user = $opts['user'] ?? null;
        $db = $this->strapi->db();

        // only name is updated
        if (!array_key_exists('parent', $data)) {
            $existingFolder = $db->query(Constants::FOLDER_MODEL_UID)->findOne(['where' => ['id' => $id]]);

            if (!is_array($existingFolder)) {
                return null;
            }

            $newFolder = array_key_exists('name', $data) ? ['name' => $data['name']] : [];
            $newFolder = $user ? SetCreatorFields::apply($newFolder, ['user' => $user, 'isEdition' => true]) : $newFolder;

            $folder = $db->query(Constants::FOLDER_MODEL_UID)->update(['where' => ['id' => $id], 'data' => $newFolder]);

            return is_array($folder) ? $folder : null;
        }

        // location is updated => using transaction
        $parent = $data['parent'];
        $db->transaction(function () use ($db, $id, $parent): void {
            // fetch existing folder
            $existingFolder = $db->queryBuilder(Constants::FOLDER_MODEL_UID)
                ->select(['pathId', 'path'])
                ->where(['id' => $id])
                ->forUpdate()
                ->first()
                ->execute();
            if (!is_array($existingFolder)) {
                throw new \TypeError("Cannot read properties of undefined (reading 'path')");
            }

            // update parent folder (delete + insert; upsert not possible)
            $joinTable = $db->metadata(Constants::FOLDER_MODEL_UID)['attributes']['parent']['joinTable'];
            $db->sql()->from($joinTable['name'])
                ->where($joinTable['joinColumn']['name'], $id)
                ->delete()
                ->run();

            if ($parent !== null) {
                $db->sql()->from($joinTable['name'])
                    ->insert([[$joinTable['inverseJoinColumn']['name'] => $parent, $joinTable['joinColumn']['name'] => $id]])
                    ->run();
            }

            // fetch destinationFolder path
            $destinationFolderPath = '/';
            if ($parent !== null) {
                $destinationFolder = $db->queryBuilder(Constants::FOLDER_MODEL_UID)
                    ->select('path')
                    ->where(['id' => $parent])
                    ->first()
                    ->execute();
                $destinationFolderPath = is_array($destinationFolder) ? (string) $destinationFolder['path'] : '/';
            }

            $folderTable = $db->metadata(Constants::FOLDER_MODEL_UID)['tableName'];
            $fileTable = $db->metadata(Constants::FILE_MODEL_UID)['tableName'];
            $folderPathColumnName = $db->metadata(Constants::FILE_MODEL_UID)['attributes']['folderPath']['columnName'];
            $pathColumnName = $db->metadata(Constants::FOLDER_MODEL_UID)['attributes']['path']['columnName'];

            $existingPath = (string) $existingFolder['path'];
            $newPath = Strings::joinBy('/', $destinationFolderPath, (string) $existingFolder['pathId']);

            // update folders below
            $sql = $db->sql();
            $sql->from($folderTable)
                ->where($pathColumnName, $existingPath)
                ->orWhere($pathColumnName, 'like', "{$existingPath}/%")
                ->update([$pathColumnName => $sql->raw('REPLACE(' . $sql->quoteIdentifier($pathColumnName) . ', ?, ?)', [$existingPath, $newPath])])
                ->run();

            // update files below
            $sql = $db->sql();
            $sql->from($fileTable)
                ->where($folderPathColumnName, $existingPath)
                ->orWhere($folderPathColumnName, 'like', "{$existingPath}/%")
                ->update([$folderPathColumnName => $sql->raw('REPLACE(' . $sql->quoteIdentifier($folderPathColumnName) . ', ?, ?)', [$existingPath, $newPath])])
                ->run();
        });

        // update less critical information (name + updatedBy)
        $newFolder = array_key_exists('name', $data) ? ['name' => $data['name']] : [];
        $newFolder = $user ? SetCreatorFields::apply($newFolder, ['user' => $user, 'isEdition' => true]) : $newFolder;

        $folder = $db->query(Constants::FOLDER_MODEL_UID)->update(['where' => ['id' => $id], 'data' => $newFolder]);

        $this->strapi->eventHub()->emit('media-folder.update', ['folder' => $folder]);

        return is_array($folder) ? $folder : null;
    }

    /**
     * Check if a folder exists in database
     *
     * @param array<string, mixed> $params query params to find the folder
     */
    public function exists(array $params = []): bool
    {
        $count = $this->strapi->db()->query(Constants::FOLDER_MODEL_UID)->count(['where' => $params]);

        return $count > 0;
    }

    /**
     * Returns the nested structure of folders
     *
     * @return list<array<string, mixed>>
     */
    public function getStructure(): array
    {
        $db = $this->strapi->db();
        $meta = $db->metadata(Constants::FOLDER_MODEL_UID);
        $joinTable = $meta['attributes']['parent']['joinTable'];

        $sql = $db->sql();
        $rows = $sql->from($meta['tableName'], 't0')
            ->select(['t0.id', 't0.name', $sql->raw($sql->quoteRef('t1.' . $joinTable['inverseJoinColumn']['name']) . ' AS ' . $sql->quoteIdentifier('parent'))])
            ->leftJoin($joinTable['name'], 't1', static function ($on) use ($joinTable): void {
                $on->on('t1.' . $joinTable['joinColumn']['name'], 't0.' . $joinTable['joinColumn']['referencedColumn']);
            })
            ->run();
        $folders = is_array($rows) ? $rows : [];

        /** @var array<string, array<string, mixed>> $folderMap */
        $folderMap = [
            'null' => ['children' => []],
        ];

        foreach ($folders as $f) {
            $folderMap[(string) $f['id']] = [...$f, 'id' => (int) $f['id'], 'children' => []];
        }

        foreach ($folders as $f) {
            $parentId = !empty($f['parent']) ? (string) $f['parent'] : 'null';

            if (!isset($folderMap[$parentId])) {
                $folderMap[$parentId] = ['children' => []];
            }

            $folderMap[$parentId]['children'][] = (string) $f['id'];
            unset($folderMap[(string) $f['id']]['parent']);
        }

        // children reference their nodes (JavaScript objects are shared), resolved and sorted by name
        $resolve = static function (string $key) use (&$resolve, $folderMap): array {
            $node = $folderMap[$key];
            $children = array_map(static fn (string $childKey): array => $resolve($childKey), $node['children']);
            usort($children, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
            $node['children'] = $children;

            return $node;
        };

        return $resolve('null')['children'];
    }
}
