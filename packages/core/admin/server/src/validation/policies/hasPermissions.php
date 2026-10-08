<?php

declare(strict_types=1);

namespace Strapi\Admin\Validation\Policies;

use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/validation/policies/hasPermissions.ts. */
final class HasPermissions
{
    public static function hasPermissionsSchema(): YupObject
    {
        return Yup::object([
            'actions' => Yup::array()->of(
                Yup::lazy(static function (mixed $val): \Strapi\Utils\Yup\Yup {
                    if (is_array($val) && array_is_list($val)) {
                        return Yup::array()->of(Yup::string())->min(1)->max(2);
                    }

                    if (is_string($val)) {
                        return Yup::string()->required();
                    }

                    return Yup::object()->shape([
                        'action' => Yup::string()->required(),
                        'subject' => Yup::string(),
                    ]);
                }),
            ),
        ]);
    }

    /** @throws \Strapi\Utils\Errors\YupValidationError */
    public static function validateHasPermissionsInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::hasPermissionsSchema())($body, $errorMessage);
    }
}
