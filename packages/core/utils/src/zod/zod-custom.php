<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.custom(fn, params)`: any value for which `fn($value)` is truthy (every value without
 * `fn`). The failure is a `custom` issue that aborts later checks.
 */
class ZodCustom extends ZodType
{
    /** @param string|array<string, mixed>|null $params */
    public function __construct(?\Closure $fn = null, string|array|null $params = null)
    {
        $this->withParams($params);
        $this->checks[] = ZodCheck::refine($fn ?? static fn (): bool => true, $params, true);
    }

    public function type(): string
    {
        return 'custom';
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        return $payload;
    }
}
