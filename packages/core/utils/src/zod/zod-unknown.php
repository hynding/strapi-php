<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.unknown()`: accepts every value. As in zod 4.4.3, an object key typed `z.unknown()` is still
 * required (a missing key reports `expected: "nonoptional"`) unless marked `.optional()`.
 */
class ZodUnknown extends ZodType
{
    /** @param string|array<string, mixed>|null $params */
    public function __construct(string|array|null $params = null)
    {
        $this->withParams($params);
    }

    public function type(): string
    {
        return 'unknown';
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        return $payload;
    }
}
