<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Validation;

use Strapi\Plugin\I18n\Constants\Constants;
use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/validation/locales.ts. */
final class Locales
{
    /** @return list<string> */
    private static function allowedLocaleCodes(): array
    {
        return array_map(static fn (array $locale): string => $locale['code'], Constants::isoLocales());
    }

    public static function createLocaleSchema(): YupObject
    {
        return Yup::object([
            'name' => Yup::string()->max(50)->nullable(),
            'code' => Yup::string()->oneOf(self::allowedLocaleCodes())->required(),
            'isDefault' => Yup::boolean()->required(),
        ])->noUnknown();
    }

    public static function updateLocaleSchema(): YupObject
    {
        return Yup::object([
            'name' => Yup::string()->min(1)->max(50)->nullable(),
            'isDefault' => Yup::boolean(),
        ])->noUnknown();
    }

    /** @throws \Strapi\Utils\Errors\ValidationError */
    public static function validateCreateLocaleInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::createLocaleSchema())($body, $errorMessage);
    }

    /** @throws \Strapi\Utils\Errors\ValidationError */
    public static function validateUpdateLocaleInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::updateLocaleSchema())($body, $errorMessage);
    }
}
