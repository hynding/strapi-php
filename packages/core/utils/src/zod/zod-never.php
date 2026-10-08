<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.never()`: rejects every value.
 */
class ZodNever extends ZodType
{
    /** @param string|array<string, mixed>|null $params */
    public function __construct(string|array|null $params = null)
    {
        $this->withParams($params);
    }

    public function type(): string
    {
        return 'never';
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        return $this->issue($payload, ['expected' => 'never', 'code' => 'invalid_type']);
    }
}
