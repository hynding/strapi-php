<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Controllers\Validation;

use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/controllers/validation/component-category.ts (its default export is `validateComponentCategory`). */
final class ComponentCategory
{
    public static function componentCategorySchema(): YupObject
    {
        return Yup::object([
            'name' => Yup::string()->min(3)->test(Common::isValidCategoryName())->required('name.required'),
        ])->noUnknown();
    }

    public static function validateComponentCategory(mixed $body): mixed
    {
        return Validators::validateYupSchema(self::componentCategorySchema())($body);
    }
}
