<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.any()`: accepts every value. As in zod 4.4.3, an object key typed `z.any()` is still
 * required (a missing key reports `expected: "nonoptional"`) unless marked `.optional()`.
 */
class ZodAny extends ZodType
{
    /** @param string|array<string, mixed>|null $params */
    public function __construct(string|array|null $params = null)
    {
        $this->withParams($params);
    }

    public function type(): string
    {
        return 'any';
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        return $payload;
    }
}
