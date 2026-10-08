<?php

declare(strict_types=1);

namespace Strapi\Upload\Controllers\Validation\Admin;

use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/controllers/validation/admin/settings.ts. */
final class Settings
{
    public static function settingsSchema(): YupObject
    {
        return Yup::object([
            'sizeOptimization' => Yup::boolean()->required(),
            'responsiveDimensions' => Yup::boolean()->required(),
            'autoOrientation' => Yup::boolean(),
            'aiMetadata' => Yup::boolean()->default(true),
        ]);
    }

    /** the keys of the schema (`Object.keys(settingsSchema.fields)`) */
    public const array SETTINGS_KEYS = ['sizeOptimization', 'responsiveDimensions', 'autoOrientation', 'aiMetadata'];

    /** @return array<string, mixed> */
    public static function validateSettings(mixed $body): array
    {
        $result = Validators::validateYupSchema(self::settingsSchema())($body);

        return is_array($result) ? $result : [];
    }
}
