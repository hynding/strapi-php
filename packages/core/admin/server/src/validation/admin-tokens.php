<?php

declare(strict_types=1);

namespace Strapi\Admin\Validation;

use Strapi\Admin\Services\Constants;
use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/validation/admin-tokens.ts. */
final class AdminTokens
{
    public static function adminTokenCreationSchema(): YupObject
    {
        return Yup::object()
            ->shape([
                'kind' => Yup::string()->oneOf(['admin'])->optional(),
                'name' => Yup::string()->min(1)->required(),
                'description' => Yup::string()->optional(),
                'lifespan' => Yup::number()->min(1)->oneOf(array_values(Constants::API_TOKEN_LIFESPANS))->nullable(),
                'adminPermissions' => Yup::array()->of(CommonValidators::permission()),
                // adminUserOwner is set by the controller from ctx.state.user (full user object) or a strapiID from body
                'adminUserOwner' => Yup::mixed()->nullable(),
            ])
            ->noUnknown()
            ->strict();
    }

    public static function adminTokenUpdateSchema(): YupObject
    {
        return Yup::object()
            ->shape([
                'name' => Yup::string()->min(1)->notNull(),
                'description' => Yup::string()->nullable(),
                'adminPermissions' => Yup::array()->of(CommonValidators::permission())->nullable(),
            ])
            ->noUnknown()
            ->strict();
    }

    /** @throws \Strapi\Utils\Errors\YupValidationError */
    public static function validateAdminTokenCreationInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::adminTokenCreationSchema())($body, $errorMessage);
    }

    /** @throws \Strapi\Utils\Errors\YupValidationError */
    public static function validateAdminTokenUpdateInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::adminTokenUpdateSchema())($body, $errorMessage);
    }
}
