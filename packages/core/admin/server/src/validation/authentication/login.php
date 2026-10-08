<?php

declare(strict_types=1);

namespace Strapi\Admin\Validation\Authentication;

use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupObject;

/** Port of server/src/validation/authentication/login.ts. */
final class Login
{
    /**
     * Validates optional session-related fields for login requests.
     * Does not constrain credential fields (email/password) handled by passport.
     */
    public static function schema(): YupObject
    {
        return Yup::object([
            'deviceId' => Yup::string()->uuid()->optional(),
            'rememberMe' => Yup::boolean()->optional(),
        ])
            // Allow other properties (like email/password) to be present
            ->noUnknown(false);
    }

    /** The default export: `validateYupSchema(schema)`. */
    public static function validateLoginSessionInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return Validators::validateYupSchema(self::schema())($body, $errorMessage);
    }
}
