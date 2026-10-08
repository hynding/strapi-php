<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Validation;

use Strapi\Core\Strapi;
use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/validation/content-types.ts. */
final class ContentTypes
{
    public static function validateGetNonLocalizedAttributesSchema(Strapi $strapi): YupObject
    {
        return Yup::object([
            'model' => Yup::string()->required(),
            'id' => Yup::mixed()->when('model', [
                'is' => static function (mixed $model) use ($strapi): bool {
                    $contentType = is_string($model) ? ($strapi->contentTypes()[$model] ?? null) : null;

                    return $contentType?->kind === 'singleType';
                },
                'then' => Yup::strapiID()->nullable(),
                'otherwise' => Yup::strapiID()->required(),
            ]),
            'locale' => Yup::string()->required(),
        ])->noUnknown()->required();
    }

    /** @throws \Strapi\Utils\Errors\ValidationError */
    public static function validateGetNonLocalizedAttributesInput(Strapi $strapi, mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::validateGetNonLocalizedAttributesSchema($strapi))($body, $errorMessage);
    }

    public static function validateFillFromLocaleInputSchema(): YupObject
    {
        return Yup::object([
            'documentId' => Yup::string()->when('collectionType', [
                'is' => 'single-types',
                'then' => static fn (Yup\Yup $schema): Yup\Yup => $schema->nullable(),
                'otherwise' => static fn (Yup\Yup $schema): Yup\Yup => $schema->required(),
            ]),
            'sourceLocale' => Yup::string()->required(),
            'targetLocale' => Yup::string()->required(),
            'collectionType' => Yup::string()->oneOf(['collection-types', 'single-types'])->required(),
        ])->noUnknown()->required();
    }

    /** @throws \Strapi\Utils\Errors\ValidationError */
    public static function validateFillFromLocaleInput(mixed $query, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::validateFillFromLocaleInputSchema())($query, $errorMessage);
    }
}
