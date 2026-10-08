<?php

declare(strict_types=1);

namespace Strapi\Upload\Controllers\Validation\Admin;

use Strapi\Core\Strapi;
use Strapi\Upload\Constants;
use Strapi\Upload\Controllers\Utils\Folders;
use Strapi\Upload\Utils\Utils as UploadUtils;
use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\TestContext;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/controllers/validation/admin/folder.ts. */
final class Folder
{
    private const string NO_SLASH_REGEX = '/^[^\/]+$/';
    private const string NO_SPACES_AROUND = '/^(?! ).+(?<! )$/s';

    private static function isNameUniqueInFolder(Strapi $strapi, int|string|null $id = null): \Closure
    {
        return static function (mixed $name, TestContext $context) use ($strapi, $id): bool {
            $folderService = UploadUtils::getService('folder', $strapi);
            $parentValue = is_array($context->parent) ? ($context->parent['parent'] ?? null) : null;
            $filters = ['name' => $name instanceof Yup\Undefined ? null : $name, 'parent' => $parentValue ?: null];
            if ($id) {
                $filters['id'] = ['$ne' => $id];

                if ($name === null || $name instanceof Yup\Undefined) {
                    $existingFolder = $strapi->db()->query(Constants::FOLDER_MODEL_UID)->findOne(['where' => ['id' => $id]]);
                    $filters['name'] = is_array($existingFolder) ? ($existingFolder['name'] ?? null) : null;
                }
            }

            $doesExist = $folderService->exists($filters);

            return !$doesExist;
        };
    }

    private static function folderExists(Strapi $strapi): \Closure
    {
        return static fn (mixed $folderId): bool => Utils::folderExists($strapi, $folderId);
    }

    private static function validateCreateFolderSchema(Strapi $strapi): YupObject
    {
        return Yup::object()
            ->shape([
                'name' => Yup::string()
                    ->min(1)
                    ->matches(self::NO_SLASH_REGEX, 'name cannot contain slashes')
                    ->matches(self::NO_SPACES_AROUND, 'name cannot start or end with a whitespace')
                    ->required()
                    ->test('is-folder-unique', 'A folder with this name already exists', self::isNameUniqueInFolder($strapi)),
                'parent' => Yup::strapiID()
                    ->nullable()
                    ->test('folder-exists', 'parent folder does not exist', self::folderExists($strapi)),
            ])
            ->noUnknown()
            ->required();
    }

    private static function validateUpdateFolderSchema(Strapi $strapi, int|string $id): YupObject
    {
        return Yup::object()
            ->shape([
                'name' => Yup::string()
                    ->min(1)
                    ->matches(self::NO_SLASH_REGEX, 'name cannot contain slashes')
                    ->matches(self::NO_SPACES_AROUND, 'name cannot start or end with a whitespace')
                    ->test('is-folder-unique', 'A folder with this name already exists', self::isNameUniqueInFolder($strapi, $id)),
                'parent' => Yup::strapiID()
                    ->nullable()
                    ->test('folder-exists', 'parent folder does not exist', self::folderExists($strapi))
                    ->test('dont-move-inside-self', 'folder cannot be moved inside itself', static function (mixed $parent) use ($strapi, $id): bool {
                        if ($parent === null || $parent instanceof Yup\Undefined) {
                            return true;
                        }

                        $destinationFolder = $strapi->db()->query(Constants::FOLDER_MODEL_UID)->findOne([
                            'select' => ['path'],
                            'where' => ['id' => $parent],
                        ]);

                        $currentFolder = $strapi->db()->query(Constants::FOLDER_MODEL_UID)->findOne([
                            'select' => ['path'],
                            'where' => ['id' => $id],
                        ]);

                        if (!is_array($destinationFolder) || !is_array($currentFolder)) {
                            return true;
                        }

                        return !Folders::isFolderOrChild(
                            ['path' => (string) $destinationFolder['path']],
                            ['path' => (string) $currentFolder['path']],
                        );
                    }),
            ])
            ->noUnknown()
            ->required();
    }

    public static function validateCreateFolder(Strapi $strapi, mixed $body): mixed
    {
        return Validators::validateYupSchema(self::validateCreateFolderSchema($strapi))($body);
    }

    public static function validateUpdateFolder(Strapi $strapi, int|string $id, mixed $body): mixed
    {
        return Validators::validateYupSchema(self::validateUpdateFolderSchema($strapi, $id))($body);
    }
}
