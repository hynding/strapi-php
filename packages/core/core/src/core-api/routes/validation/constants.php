<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Routes\Validation;

/** Port of packages/core/core/src/core-api/routes/validation/constants.ts. */
final class Constants
{
    /**
     * Defines the constant literal values for boolean representations.
     *
     * These values can be used to convert string representations of booleans (for example,
     * 'true', 'false', '1', '0') into actual boolean types.
     */
    public const BOOLEAN_LITERAL_VALUES = ['t', '1', 'true', 'f', '0', 'false'];
}
