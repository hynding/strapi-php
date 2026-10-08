<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Controllers\Validation;

use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\TestContext;
use Strapi\Utils\Yup\Undefined;
use Strapi\Utils\Yup\Yup as YupSchema;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/controllers/validation/user.js. */
final class User
{
    public static function deleteRoleSchema(): YupObject
    {
        return Yup::object()->shape([
            'role' => Yup::strapiID()->required(),
        ]);
    }

    /**
     * A role relation entry may be referenced by numeric `id` (legacy) or by
     * `documentId` (the v5 default), but must carry at least one of them.
     */
    public static function roleRelationEntrySchema(): YupObject
    {
        return Yup::object()
            ->shape(['id' => Yup::strapiID(), 'documentId' => Yup::strapiID()])
            ->test(
                'id-or-documentId',
                'Relation entry must include an id or documentId',
                static fn (mixed $entry): bool => is_array($entry) && (($entry['id'] ?? null) !== null || ($entry['documentId'] ?? null) !== null),
            );
    }

    /** `typeof value === 'object'` (null included) */
    private static function isObject(mixed $value): bool
    {
        return $value === null || is_array($value) || (is_object($value) && !$value instanceof Undefined);
    }

    public static function createUserBodySchema(): YupObject
    {
        return Yup::object()->shape([
            'email' => Yup::string()->email()->required(),
            'username' => Yup::string()->min(1)->required(),
            'password' => Yup::string()->min(1)->required(),
            'role' => Yup::lazy(static fn (mixed $value): YupSchema => self::isObject($value)
                ? Yup::object()
                    ->shape([
                        'connect' => Yup::array()
                            ->of(self::roleRelationEntrySchema())
                            ->min(1, 'Users must have a role')
                            ->required(),
                    ])
                    ->required()
                : Yup::strapiID()->required()),
        ]);
    }

    public static function updateUserBodySchema(): YupObject
    {
        return Yup::object()->shape([
            'email' => Yup::string()->email()->min(1),
            'username' => Yup::string()->min(1),
            'password' => Yup::mixed()
                ->test(
                    'password-validation',
                    'Password must be at least 1 character',
                    static function (mixed $value): bool {
                        if ($value === null || $value instanceof Undefined || $value === '') {
                            return true;
                        }

                        return is_string($value) && mb_strlen($value) >= 1;
                    }
                ),
            'role' => Yup::lazy(static fn (mixed $value): YupSchema => self::isObject($value)
                ? Yup::object()->shape([
                    // connect/disconnect are each optional (matching core relation inputs),
                    // but a role must remain: reject disconnecting every role without
                    // connecting a replacement.
                    'connect' => Yup::array()->of(self::roleRelationEntrySchema()),
                    'disconnect' => Yup::array()
                        ->of(self::roleRelationEntrySchema())
                        ->test('CheckDisconnect', 'Cannot remove role', static function (mixed $disconnect, TestContext $ctx) use ($value): bool {
                            $connectValue = is_array($value) && is_array($value['connect'] ?? null) ? $value['connect'] : [];
                            $disconnectValue = is_array($disconnect) ? $disconnect : [];
                            if (count($connectValue) === 0 && count($disconnectValue) > 0) {
                                return false;
                            }

                            return true;
                        }),
                ])
                : Yup::strapiID()),
        ]);
    }

    public static function validateCreateUserBody(mixed $payload): mixed
    {
        return Validators::validateYupSchema(self::createUserBodySchema())($payload);
    }

    public static function validateUpdateUserBody(mixed $payload): mixed
    {
        return Validators::validateYupSchema(self::updateUserBodySchema())($payload);
    }

    public static function validateDeleteRoleBody(mixed $payload): mixed
    {
        return Validators::validateYupSchema(self::deleteRoleSchema())($payload);
    }
}
