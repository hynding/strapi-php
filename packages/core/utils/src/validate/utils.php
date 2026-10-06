<?php

declare(strict_types=1);

namespace Strapi\Utils\Validate;

use Strapi\Utils\Errors\ValidationError;

/** Port of packages/core/utils/src/validate/utils.ts. */
final class Utils
{
    /**
     * @param array{key: string, path?: string|null, reason?: string|null} $params
     */
    public static function throwInvalidKey(array $params): never
    {
        $key = $params['key'];
        $path = $params['path'] ?? null;
        $reason = $params['reason'] ?? null;

        $location = $path !== null && $path !== '' && $path !== $key ? "Invalid key {$key} at {$path}" : "Invalid key {$key}";
        $msg = $reason !== null && $reason !== '' ? "{$location}: {$reason}" : $location;

        throw new ValidationError($msg, ['key' => $key, 'path' => $path]);
    }

    /**
     * Curry a function: `curried(1)(2)(3)`, `curried(1, 2)(3)` and `curried(1, 2, 3)` all call it with 3 args.
     *
     * @return \Closure(mixed ...): mixed
     */
    public static function asyncCurry(callable $fn, ?int $arity = null): \Closure
    {
        $arity ??= (new \ReflectionFunction($fn(...)))->getNumberOfRequiredParameters();

        $curried = static function (mixed ...$args) use ($fn, $arity, &$curried): mixed {
            if (count($args) >= $arity) {
                return $fn(...$args);
            }

            return static fn (mixed ...$more): mixed => $curried(...$args, ...$more);
        };

        return $curried;
    }
}
