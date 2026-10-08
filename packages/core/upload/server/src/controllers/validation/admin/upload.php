<?php

declare(strict_types=1);

namespace Strapi\Upload\Controllers\Validation\Admin;

use Strapi\Core\Strapi;
use Strapi\Upload\Utils\Utils as UploadUtils;
use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/controllers/validation/admin/upload.ts. */
final class Upload
{
    private static function focalPointSchema(): YupObject
    {
        return Yup::object([
            'x' => Yup::number()->min(0)->max(100)->required(),
            'y' => Yup::number()->min(0)->max(100)->required(),
        ])->nullable()->default(null);
    }

    private static function fileInfoSchema(Strapi $strapi): YupObject
    {
        return Yup::object([
            'name' => Yup::string()->nullable(),
            'alternativeText' => Yup::string()->nullable(),
            'caption' => Yup::string()->nullable(),
            'focalPoint' => self::focalPointSchema(),
            'folder' => Yup::strapiID()
                ->nullable()
                ->test('folder-exists', 'the folder does not exist', static function (mixed $folderId) use ($strapi): bool {
                    if ($folderId === null || $folderId instanceof Yup\Undefined) {
                        return true;
                    }

                    return UploadUtils::getService('folder', $strapi)->exists(['id' => $folderId]);
                }),
        ]);
    }

    /** @return array<string, mixed> */
    public static function validateUploadBody(Strapi $strapi, mixed $data = [], bool $isMulti = false): array
    {
        $schema = $isMulti
            ? Yup::object(['fileInfo' => Yup::array()->of(self::fileInfoSchema($strapi))])
            : Yup::object(['fileInfo' => self::fileInfoSchema($strapi)]);

        $result = Validators::validateYupSchema($schema, ['strict' => false])($data ?? []);

        return is_array($result) ? $result : [];
    }

    /** @return array{updates: list<array{id: int, fileInfo: array<string, mixed>}>} */
    public static function validateBulkUpdateBody(Strapi $strapi, mixed $body): array
    {
        $bulkUpdatesSchema = Yup::object([
            'updates' => Yup::array()
                ->of(
                    Yup::object([
                        'id' => Yup::number()->required(),
                        'fileInfo' => self::fileInfoSchema($strapi)->required(),
                    ]),
                )
                ->min(1)
                ->required(),
        ]);

        /** @var array{updates: list<array{id: int, fileInfo: array<string, mixed>}>} */
        return Validators::validateYupSchema($bulkUpdatesSchema)($body);
    }
}
