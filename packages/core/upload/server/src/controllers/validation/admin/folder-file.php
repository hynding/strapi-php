<?php

declare(strict_types=1);

namespace Strapi\Upload\Controllers\Validation\Admin;

use Strapi\Core\Strapi;
use Strapi\Upload\Constants;
use Strapi\Upload\Controllers\Utils\Folders;
use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\TestContext;

/** Port of server/src/controllers/validation/admin/folder-file.ts. */
final class FolderFile
{
    public static function validateDeleteManyFoldersFiles(mixed $body): mixed
    {
        $schema = Yup::object()
            ->shape([
                'fileIds' => Yup::array()->of(Yup::strapiID()->required()),
                'folderIds' => Yup::array()->of(Yup::strapiID()->required()),
            ])
            ->noUnknown()
            ->required();

        return Validators::validateYupSchema($schema)($body);
    }

    /** @return list<mixed> */
    private static function ids(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    public static function validateMoveManyFoldersFiles(Strapi $strapi, mixed $body): void
    {
        $structure = Yup::object()
            ->shape([
                'destinationFolderId' => Yup::strapiID()
                    ->nullable()
                    ->defined()
                    ->test('folder-exists', 'destination folder does not exist', static fn (mixed $folderId): bool => Utils::folderExists($strapi, $folderId)),
                'fileIds' => Yup::array()->of(Yup::strapiID()->required()),
                'folderIds' => Yup::array()->of(Yup::strapiID()->required()),
            ])
            ->noUnknown()
            ->required();

        $duplicates = Yup::object()->test('are-folders-unique', 'some folders already exist', static function (mixed $value, TestContext $context) use ($strapi): bool|Yup\YupError {
            $folderIds = self::ids(is_array($value) ? ($value['folderIds'] ?? null) : null);
            $destinationFolderId = is_array($value) ? ($value['destinationFolderId'] ?? null) : null;
            if ($folderIds === []) {
                return true;
            }

            $folders = $strapi->db()->query(Constants::FOLDER_MODEL_UID)->findMany([
                'select' => ['name'],
                'where' => ['id' => ['$in' => $folderIds]],
            ]);

            $existingFolders = $strapi->db()->query(Constants::FOLDER_MODEL_UID)->findMany([
                'select' => ['name'],
                'where' => ['parent' => ['id' => $destinationFolderId]],
            ]);

            $existingNames = array_map(static fn (array $f): mixed => $f['name'] ?? null, $existingFolders);
            $duplicatedNames = array_values(array_unique(array_filter(
                array_map(static fn (array $f): mixed => $f['name'] ?? null, $folders),
                static fn (mixed $name): bool => in_array($name, $existingNames, true),
            )));
            if ($duplicatedNames !== []) {
                return $context->createError([
                    'message' => 'some folders already exists: ' . implode(', ', array_map(strval(...), $duplicatedNames)),
                ]);
            }

            return true;
        });

        $notInsideThemselves = Yup::object()->test(
            'dont-move-inside-self',
            'folders cannot be moved inside themselves or one of its children',
            static function (mixed $value, TestContext $context) use ($strapi): bool|Yup\YupError {
                $folderIds = self::ids(is_array($value) ? ($value['folderIds'] ?? null) : null);
                $destinationFolderId = is_array($value) ? ($value['destinationFolderId'] ?? null) : null;
                if ($destinationFolderId === null || $folderIds === []) {
                    return true;
                }

                $destinationFolder = $strapi->db()->query(Constants::FOLDER_MODEL_UID)->findOne([
                    'select' => ['path'],
                    'where' => ['id' => $destinationFolderId],
                ]);

                $folders = $strapi->db()->query(Constants::FOLDER_MODEL_UID)->findMany([
                    'select' => ['name', 'path'],
                    'where' => ['id' => ['$in' => $folderIds]],
                ]);

                $unmovableFoldersNames = [];
                foreach ($folders as $folder) {
                    if (is_array($destinationFolder) && Folders::isFolderOrChild(['path' => (string) $destinationFolder['path']], ['path' => (string) $folder['path']])) {
                        $unmovableFoldersNames[] = (string) $folder['name'];
                    }
                }

                if ($unmovableFoldersNames !== []) {
                    return $context->createError([
                        'message' => 'folders cannot be moved inside themselves or one of its children: ' . implode(', ', $unmovableFoldersNames),
                    ]);
                }

                return true;
            },
        );

        Validators::validateYupSchema($structure)($body);
        Validators::validateYupSchema($duplicates)($body);
        Validators::validateYupSchema($notInsideThemselves)($body);
    }
}
