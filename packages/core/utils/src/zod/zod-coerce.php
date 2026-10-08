<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.coerce`: schemas that first convert the input with JavaScript's `String()`, `Number()` or
 * `Boolean()`. Reached as `Zod::coerce()->number()`.
 */
final class ZodCoerce
{
    /** @param string|array<string, mixed>|null $params */
    public function string(string|array|null $params = null): ZodString
    {
        return new ZodString($params, true);
    }

    /** @param string|array<string, mixed>|null $params */
    public function number(string|array|null $params = null): ZodNumber
    {
        return new ZodNumber($params, true);
    }

    /** @param string|array<string, mixed>|null $params */
    public function boolean(string|array|null $params = null): ZodBoolean
    {
        return new ZodBoolean($params, true);
    }
}
