<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.null()`
 */
class ZodNull extends ZodType
{
    /** @param string|array<string, mixed>|null $params */
    public function __construct(string|array|null $params = null)
    {
        $this->withParams($params);
    }

    public function type(): string
    {
        return 'null';
    }

    /** @return list<mixed> */
    public function values(): array
    {
        return [null];
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        if ($payload->value === null) {
            return $payload;
        }

        return $this->issue($payload, ['expected' => 'null', 'code' => 'invalid_type']);
    }
}
