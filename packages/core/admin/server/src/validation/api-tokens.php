<?php

declare(strict_types=1);

namespace Strapi\Admin\Validation;

use Strapi\Admin\Services\Constants;
use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/validation/api-tokens.ts. */
final class ApiTokens
{
    public static function apiTokenCreationSchema(): YupObject
    {
        return Yup::object()
            ->shape([
                'kind' => Yup::string()->oneOf(['content-api'])->optional(),
                'name' => Yup::string()->min(1)->required(),
                'description' => Yup::string()->optional(),
                'type' => Yup::string()->oneOf(array_values(Constants::API_TOKEN_TYPE))->optional(),
                'permissions' => Yup::array()->of(Yup::string())->nullable(),
                'lifespan' => Yup::number()->min(1)->oneOf(array_values(Constants::API_TOKEN_LIFESPANS))->nullable(),
            ])
            ->noUnknown()
            ->strict();
    }

    public static function apiTokenUpdateSchema(): YupObject
    {
        return Yup::object()
            ->shape([
                'name' => Yup::string()->min(1)->notNull(),
                'description' => Yup::string()->nullable(),
                'type' => Yup::string()->oneOf(array_values(Constants::API_TOKEN_TYPE))->optional(),
                'permissions' => Yup::array()->of(Yup::string())->nullable(),
            ])
            ->noUnknown()
            ->strict();
    }

    /** @throws \Strapi\Utils\Errors\YupValidationError */
    public static function validateApiTokenCreationInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::apiTokenCreationSchema())($body, $errorMessage);
    }

    /** @throws \Strapi\Utils\Errors\YupValidationError */
    public static function validateApiTokenUpdateInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::apiTokenUpdateSchema())($body, $errorMessage);
    }
}
