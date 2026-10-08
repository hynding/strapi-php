<?php

declare(strict_types=1);

namespace Strapi\Admin\Validation;

use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\TestContext;
use Strapi\Utils\Yup\Yup as YupSchema;
use Strapi\Utils\Yup\YupError;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/validation/role.ts. */
final class Role
{
    public static function roleCreateSchema(): YupObject
    {
        return Yup::object()
            ->shape([
                'name' => Yup::string()->min(1)->required(),
                'description' => Yup::string()->nullable(),
            ])
            ->noUnknown();
    }

    public static function rolesDeleteSchema(): YupObject
    {
        return Yup::object()
            ->shape([
                'ids' => Yup::array()
                    ->of(Yup::strapiID())
                    ->min(1)
                    ->required()
                    ->test('roles-deletion-checks', 'Roles deletion checks have failed', static function (mixed $ids, TestContext $ctx): bool|YupError {
                        try {
                            /** @var \Strapi\Admin\Services\Role $roleService */
                            $roleService = CommonValidators::getService('role');
                            $roleService->checkRolesIdForDeletion(is_array($ids) ? array_values($ids) : []);
                        } catch (\Throwable $e) {
                            return $ctx->createError(['path' => 'ids', 'message' => $e->getMessage()]);
                        }

                        return true;
                    }),
            ])
            ->noUnknown();
    }

    public static function roleDeleteSchema(): YupSchema
    {
        return Yup::strapiID()
            ->required()
            ->test('no-admin-single-delete', 'Role deletion checks have failed', static function (mixed $id, TestContext $ctx): bool|YupError {
                try {
                    /** @var \Strapi\Admin\Services\Role $roleService */
                    $roleService = CommonValidators::getService('role');
                    $roleService->checkRolesIdForDeletion([$id]);
                } catch (\Throwable $e) {
                    return $ctx->createError(['path' => 'id', 'message' => $e->getMessage()]);
                }

                return true;
            });
    }

    public static function roleUpdateSchema(): YupObject
    {
        return Yup::object()
            ->shape([
                'name' => Yup::string()->min(1),
                'description' => Yup::string()->nullable(),
            ])
            ->noUnknown();
    }

    /** @throws \Strapi\Utils\Errors\YupValidationError */
    public static function validateRoleCreateInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::roleCreateSchema())($body, $errorMessage);
    }

    /** @throws \Strapi\Utils\Errors\YupValidationError */
    public static function validateRoleUpdateInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::roleUpdateSchema())($body, $errorMessage);
    }

    /** @throws \Strapi\Utils\Errors\YupValidationError */
    public static function validateRolesDeleteInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::rolesDeleteSchema())($body, $errorMessage);
    }

    /** @throws \Strapi\Utils\Errors\YupValidationError */
    public static function validateRoleDeleteInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::roleDeleteSchema())($body, $errorMessage);
    }
}
