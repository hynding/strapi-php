<?php

declare(strict_types=1);

namespace Strapi\Openapi\Routes\Rules;

/** Port of packages/core/openapi/src/routes/rules/is-of-type.ts. */
final class IsOfType
{
    /** @return \Closure(array<string, mixed>): bool */
    public static function isOfType(string $type): \Closure
    {
        return static fn (array $route): bool => ($route['info']['type'] ?? null) === $type;
    }
}
