<?php

declare(strict_types=1);

namespace Strapi\Admin\Validation;

use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\Undefined;
use Strapi\Utils\Yup\Yup as YupSchema;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/validation/user.ts. */
final class User
{
    public static function userCreationSchema(): YupObject
    {
        return Yup::object([
            'email' => CommonValidators::email()->required(),
            'firstname' => CommonValidators::firstname()->required(),
            'lastname' => CommonValidators::lastname(),
            'roles' => CommonValidators::roles()->min(1),
            'preferedLanguage' => Yup::string()->nullable(),
        ])->noUnknown();
    }

    public static function profileUpdateSchema(): YupObject
    {
        return Yup::object([
            'email' => CommonValidators::email()->notNull(),
            'firstname' => CommonValidators::firstname()->notNull(),
            'lastname' => CommonValidators::lastname()->nullable(),
            'username' => CommonValidators::username()->nullable(),
            'password' => CommonValidators::password()->notNull(),
            'currentPassword' => Yup::string()
                ->when('password', static fn (mixed $password, YupSchema $schema): YupSchema => !($password instanceof Undefined) ? $schema->required() : $schema)
                ->notNull(),
            'preferedLanguage' => Yup::string()->nullable(),
        ])->noUnknown();
    }

    public static function userUpdateSchema(): YupObject
    {
        return Yup::object([
            'email' => CommonValidators::email()->notNull(),
            'firstname' => CommonValidators::firstname()->notNull(),
            'lastname' => CommonValidators::lastname()->nullable(),
            'username' => CommonValidators::username()->nullable(),
            'password' => CommonValidators::password()->notNull(),
            'isActive' => Yup::bool()->notNull(),
            'roles' => CommonValidators::roles()->min(1)->notNull(),
        ])->noUnknown();
    }

    public static function usersDeleteSchema(): YupObject
    {
        return Yup::object([
            'ids' => Yup::array()->of(Yup::strapiID())->min(1)->required(),
        ])->noUnknown();
    }

    public static function validateUserCreationInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::userCreationSchema())($body, $errorMessage);
    }

    public static function validateProfileUpdateInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::profileUpdateSchema())($body, $errorMessage);
    }

    public static function validateUserUpdateInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::userUpdateSchema())($body, $errorMessage);
    }

    public static function validateUsersDeleteInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::usersDeleteSchema())($body, $errorMessage);
    }

    /** @return array{userCreationSchema: YupObject, usersDeleteSchema: YupObject, userUpdateSchema: YupObject} */
    public static function schemas(): array
    {
        return [
            'userCreationSchema' => self::userCreationSchema(),
            'usersDeleteSchema' => self::usersDeleteSchema(),
            'userUpdateSchema' => self::userUpdateSchema(),
        ];
    }
}
