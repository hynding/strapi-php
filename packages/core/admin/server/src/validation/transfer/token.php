<?php

declare(strict_types=1);

namespace Strapi\Admin\Validation\Transfer;

use Strapi\Admin\Services\Constants;
use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/validation/transfer/token.ts. */
final class Token
{
    public static function transferTokenCreationSchema(): YupObject
    {
        return Yup::object()
            ->shape([
                'name' => Yup::string()->min(1)->required(),
                'description' => Yup::string()->optional(),
                'permissions' => Yup::array()
                    ->min(1)
                    ->of(Yup::string()->oneOf(array_values(Constants::TRANSFER_TOKEN_TYPE)))
                    ->required(),
                'lifespan' => Yup::number()
                    ->min(1)
                    ->oneOf(array_values(Constants::TRANSFER_TOKEN_LIFESPANS))
                    ->nullable(),
            ])
            ->noUnknown()
            ->strict();
    }

    public static function transferTokenUpdateSchema(): YupObject
    {
        return Yup::object()
            ->shape([
                'name' => Yup::string()->min(1)->notNull(),
                'description' => Yup::string()->nullable(),
                'permissions' => Yup::array()
                    ->min(1)
                    ->of(Yup::string()->oneOf(array_values(Constants::TRANSFER_TOKEN_TYPE)))
                    ->nullable(),
            ])
            ->noUnknown()
            ->strict();
    }

    /** @throws \Strapi\Utils\Errors\YupValidationError */
    public static function validateTransferTokenCreationInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::transferTokenCreationSchema())($body, $errorMessage);
    }

    /** @throws \Strapi\Utils\Errors\YupValidationError */
    public static function validateTransferTokenUpdateInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::transferTokenUpdateSchema())($body, $errorMessage);
    }
}
