<?php

declare(strict_types=1);

namespace Strapi\Admin\Validation\Authentication;

use Strapi\Admin\Validation\CommonValidators;
use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/validation/authentication/register.ts. */
final class Register
{
    public static function registrationSchema(): YupObject
    {
        return Yup::object([
            'registrationToken' => Yup::string()->required(),
            'userInfo' => Yup::object([
                'firstname' => CommonValidators::firstname()->required(),
                'lastname' => CommonValidators::lastname()->nullable(),
                'password' => CommonValidators::password()->required(),
            ])->required()->noUnknown(),
            'deviceId' => Yup::string()->uuid()->optional(),
            'rememberMe' => Yup::boolean()->optional(),
        ])->noUnknown();
    }

    public static function registrationInfoQuerySchema(): YupObject
    {
        return Yup::object([
            'registrationToken' => Yup::string()->required(),
        ])->required()->noUnknown();
    }

    public static function adminRegistrationSchema(): YupObject
    {
        return Yup::object([
            'email' => CommonValidators::email()->required(),
            'firstname' => CommonValidators::firstname()->required(),
            'lastname' => CommonValidators::lastname()->nullable(),
            'password' => CommonValidators::password()->required(),
            'deviceId' => Yup::string()->uuid()->optional(),
            'rememberMe' => Yup::boolean()->optional(),
        ])->required()->noUnknown();
    }

    public static function validateRegistrationInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::registrationSchema())($body, $errorMessage);
    }

    public static function validateRegistrationInfoQuery(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::registrationInfoQuerySchema())($body, $errorMessage);
    }

    public static function validateAdminRegistrationInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::adminRegistrationSchema())($body, $errorMessage);
    }
}
