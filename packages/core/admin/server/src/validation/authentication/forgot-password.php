<?php

declare(strict_types=1);

namespace Strapi\Admin\Validation\Authentication;

use Strapi\Admin\Validation\CommonValidators;
use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/validation/authentication/forgot-password.ts. */
final class ForgotPassword
{
    public static function forgotPasswordSchema(): YupObject
    {
        return Yup::object([
            'email' => CommonValidators::email()->required(),
        ])->required()->noUnknown();
    }

    /** The default export: `validateYupSchema(forgotPasswordSchema)`. */
    public static function validateForgotPasswordInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::forgotPasswordSchema())($body, $errorMessage);
    }
}
