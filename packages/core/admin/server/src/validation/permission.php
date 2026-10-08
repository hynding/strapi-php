<?php

declare(strict_types=1);

namespace Strapi\Admin\Validation;

use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\TestContext;
use Strapi\Utils\Yup\YupArray;
use Strapi\Utils\Yup\YupError;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/validation/permission.ts. */
final class Permission
{
    public static function checkPermissionsSchema(): YupObject
    {
        return Yup::object()->shape([
            'permissions' => Yup::array()->of(
                Yup::object()
                    ->shape([
                        'action' => Yup::string()->required(),
                        'subject' => Yup::string()->nullable(),
                        'field' => Yup::string(),
                    ])
                    ->noUnknown(),
            ),
        ]);
    }

    public static function checkPermissionsExist(mixed $permissions, TestContext $ctx): bool|YupError
    {
        /** @var \Strapi\Admin\Services\Permission $permissionService */
        $permissionService = CommonValidators::getService('permission');
        $existingActions = $permissionService->actionProvider->values();

        $failIndex = -1;
        foreach (is_array($permissions) ? array_values($permissions) : [] as $index => $permission) {
            $exists = false;
            foreach ($existingActions as $action) {
                $subjects = is_array($action['subjects'] ?? null) ? $action['subjects'] : [];
                if (
                    ($action['actionId'] ?? null) === ($permission['action'] ?? null)
                    && (($action['section'] ?? null) !== 'contentTypes' || in_array($permission['subject'] ?? null, $subjects, true))
                ) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                $failIndex = $index;
                break;
            }
        }

        return $failIndex === -1
            ? true
            : $ctx->createError([
                'path' => 'permissions',
                'message' => "[{$failIndex}] is not an existing permission action",
            ]);
    }

    public static function actionsExistSchema(): YupArray
    {
        return Yup::array()
            ->of(Yup::object()->shape(['conditions' => Yup::array()->of(Yup::string())]))
            ->test('actions-exist', '', self::checkPermissionsExist(...));
    }

    /** @throws \Strapi\Utils\Errors\YupValidationError */
    public static function validatePermissionsExist(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::actionsExistSchema())($body, $errorMessage);
    }

    /** @throws \Strapi\Utils\Errors\YupValidationError */
    public static function validateCheckPermissionsInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::checkPermissionsSchema())($body, $errorMessage);
    }

    /** @throws \Strapi\Utils\Errors\YupValidationError */
    public static function validatedUpdatePermissionsInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(CommonValidators::updatePermissions())($body, $errorMessage);
    }
}
