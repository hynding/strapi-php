<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Controllers\Validation;

use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\TestContext;
use Strapi\Utils\Yup\YupError;
use Strapi\Utils\Yup\YupObject;
use Strapi\Utils\Yup\YupString;

/**
 * Port of server/src/controllers/validation/auth.js.
 *
 * `config` is `plugin::users-permissions.validationRules` (`{ validatePassword?: callable }`).
 */
final class Auth
{
    public static function callbackSchema(): YupObject
    {
        return Yup::object([
            'identifier' => Yup::string()->required(),
            'password' => Yup::string()->required(),
        ]);
    }

    /** `password` with the 72-byte bcrypt limit and the project's `validatePassword` rule. */
    private static function passwordSchema(mixed $config, string $maxLengthTestName, string $validateTestName): YupString
    {
        return Yup::string()
            ->required()
            ->test($maxLengthTestName, static function (mixed $value, TestContext $ctx): bool|YupError {
                if (!is_string($value) || $value === '') {
                    return true;
                }
                $isValid = strlen($value) <= 72;
                if (!$isValid) {
                    return $ctx->createError(['message' => 'Password must be less than 73 bytes']);
                }

                return true;
            })
            ->test($validateTestName, static function (mixed $value, TestContext $ctx) use ($config): bool|YupError {
                $validatePassword = is_array($config) ? ($config['validatePassword'] ?? null) : null;
                if (is_callable($validatePassword)) {
                    try {
                        $isValid = $validatePassword($value);
                        if (!$isValid) {
                            return $ctx->createError(['message' => 'Password validation failed.']);
                        }
                    } catch (\Throwable $error) {
                        return $ctx->createError(['message' => $error->getMessage() !== '' ? $error->getMessage() : 'An error occurred.']);
                    }
                }

                return true;
            });
    }

    public static function createRegisterSchema(mixed $config): YupObject
    {
        return Yup::object([
            'email' => Yup::string()->email()->required(),
            'username' => Yup::string()->required(),
            'password' => self::passwordSchema($config, 'validateRegisterPasswordMaxLength', 'validateRegisterPassword'),
        ]);
    }

    public static function sendEmailConfirmationSchema(): YupObject
    {
        return Yup::object([
            'email' => Yup::string()->email()->required(),
        ]);
    }

    public static function validateEmailConfirmationSchema(): YupObject
    {
        return Yup::object([
            'confirmation' => Yup::string()->required(),
        ]);
    }

    public static function forgotPasswordSchema(): YupObject
    {
        return Yup::object([
            'email' => Yup::string()->email()->required(),
        ])->noUnknown();
    }

    public static function createResetPasswordSchema(mixed $config): YupObject
    {
        return Yup::object([
            'password' => self::passwordSchema($config, 'validateResetPasswordMaxLength', 'validateResetPassword'),
            'passwordConfirmation' => Yup::string()
                ->required()
                ->oneOf([Yup::ref('password')], 'Passwords do not match'),

            'code' => Yup::string()->required(),
        ])->noUnknown();
    }

    public static function createChangePasswordSchema(mixed $config): YupObject
    {
        return Yup::object([
            'password' => self::passwordSchema($config, 'validateChangePasswordMaxLength', 'validateChangePassword'),
            'passwordConfirmation' => Yup::string()
                ->required()
                ->oneOf([Yup::ref('password')], 'Passwords do not match'),
            'currentPassword' => Yup::string()->required(),
        ])->noUnknown();
    }

    /** @return array<string, mixed> */
    public static function validateCallbackBody(mixed $payload): array
    {
        return self::asArray(Validators::validateYupSchema(self::callbackSchema())($payload));
    }

    /** @return array<string, mixed> */
    public static function validateRegisterBody(mixed $payload, mixed $config = null): array
    {
        return self::asArray(Validators::validateYupSchema(self::createRegisterSchema($config))($payload));
    }

    /** @return array<string, mixed> */
    public static function validateSendEmailConfirmationBody(mixed $payload): array
    {
        return self::asArray(Validators::validateYupSchema(self::sendEmailConfirmationSchema())($payload));
    }

    /** @return array<string, mixed> */
    public static function validateEmailConfirmationBody(mixed $payload): array
    {
        return self::asArray(Validators::validateYupSchema(self::validateEmailConfirmationSchema())($payload));
    }

    /** @return array<string, mixed> */
    public static function validateForgotPasswordBody(mixed $payload): array
    {
        return self::asArray(Validators::validateYupSchema(self::forgotPasswordSchema())($payload));
    }

    /** @return array<string, mixed> */
    public static function validateResetPasswordBody(mixed $payload, mixed $config = null): array
    {
        return self::asArray(Validators::validateYupSchema(self::createResetPasswordSchema($config))($payload));
    }

    /** @return array<string, mixed> */
    public static function validateChangePasswordBody(mixed $payload, mixed $config = null): array
    {
        return self::asArray(Validators::validateYupSchema(self::createChangePasswordSchema($config))($payload));
    }

    /** @return array<string, mixed> */
    private static function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
