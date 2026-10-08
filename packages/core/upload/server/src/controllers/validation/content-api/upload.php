<?php

declare(strict_types=1);

namespace Strapi\Upload\Controllers\Validation\ContentApi;

use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/controllers/validation/content-api/upload.ts. */
final class Upload
{
    private static function fileInfoSchema(): YupObject
    {
        $focalPointSchema = Yup::object([
            'x' => Yup::number()->min(0)->max(100)->required(),
            'y' => Yup::number()->min(0)->max(100)->required(),
        ])->nullable()->default(null);

        return Yup::object([
            'name' => Yup::string()->nullable(),
            'alternativeText' => Yup::string()->nullable(),
            'caption' => Yup::string()->nullable(),
            'focalPoint' => $focalPointSchema,
        ])->noUnknown();
    }

    /** @return array<string, mixed> */
    public static function validateUploadBody(mixed $data = [], bool $isMulti = false): array
    {
        $schema = $isMulti
            ? Yup::object(['fileInfo' => Yup::array()->of(self::fileInfoSchema())])
            : Yup::object(['fileInfo' => self::fileInfoSchema()]);

        $result = Validators::validateYupSchema($schema, ['strict' => false])($data ?? []);

        return is_array($result) ? $result : [];
    }
}
