<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Services\Helpers\Utils;

/** Port of server/src/services/helpers/utils/routes.ts. */
final class Routes
{
    public static function hasFindMethod(mixed $handler): bool
    {
        if (is_string($handler)) {
            $parts = explode('.', $handler);

            return end($parts) === 'find';
        }

        return false;
    }
}
