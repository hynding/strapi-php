<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Validation;

use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/validation/settings.ts (the inferred `Settings` type is `array{aiLocalizations?: bool}`). */
final class Settings
{
    public static function settingsSchema(): YupObject
    {
        return Yup::object([
            'aiLocalizations' => Yup::boolean()->default(false),
        ]);
    }

    /** The default export: `validateYupSchema(settingsSchema)` */
    public static function validateSettings(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::settingsSchema())($body, $errorMessage);
    }
}
