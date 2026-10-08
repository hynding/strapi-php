<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils;

use Strapi\DataTransfer\Errors\Providers\ProviderInitializationError;

/**
 * Port of src/utils/providers.ts.
 */
final class Providers
{
    /** @phpstan-assert !null $strapi */
    public static function assertValidStrapi(mixed $strapi, string $msg = ''): void
    {
        if (!$strapi) {
            throw new ProviderInitializationError("{$msg}. Strapi instance not found.");
        }
    }
}
