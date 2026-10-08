<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Validation\Policies;

use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodObject;

/** Port of server/src/validation/policies/hasPermissions.ts. */
final class HasPermissions
{
    public static function hasPermissionsSchema(): ZodObject
    {
        return z::object([
            'actions' => z::array(z::string())->optional(),
            'hasAtLeastOne' => z::boolean()->optional(),
        ]);
    }

    /** @throws \Strapi\Utils\Errors\ValidationError */
    public static function validateHasPermissionsInput(mixed $body, ?string $errorMessage = null): mixed
    {
        return z::validateZodSchema(self::hasPermissionsSchema())($body, $errorMessage);
    }
}
