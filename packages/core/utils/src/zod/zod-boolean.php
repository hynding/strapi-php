<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.boolean()` (and `z.coerce.boolean()`, which applies JavaScript truthiness).
 */
class ZodBoolean extends ZodType
{
    /** @param string|array<string, mixed>|null $params */
    public function __construct(string|array|null $params = null, protected bool $coerce = false)
    {
        $this->withParams($params);
    }

    public function type(): string
    {
        return 'boolean';
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        if ($this->coerce) {
            $payload->value = Util::jsTruthy($payload->value);
        }
        if (is_bool($payload->value)) {
            return $payload;
        }

        return $this->issue($payload, ['expected' => 'boolean', 'code' => 'invalid_type']);
    }
}
