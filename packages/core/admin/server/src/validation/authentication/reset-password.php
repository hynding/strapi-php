<?php

declare(strict_types=1);

namespace Strapi\Admin\Validation\Authentication;

use Strapi\Admin\Validation\CommonValidators;
use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/validation/authentication/reset-password.ts. */
final class ResetPassword
{
    public static function resetPasswordSchema(): YupObject
    {
        return Yup::object([
            'resetPasswordToken' => Yup::string()->required(),
            'password' => CommonValidators::password()->required(),
        ])->required()->noUnknown();
    }

    /** The default export: `validateYupSchema(resetPasswordSchema)`. */
    public static function validateResetPasswordInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::resetPasswordSchema())($body, $errorMessage);
    }
}
