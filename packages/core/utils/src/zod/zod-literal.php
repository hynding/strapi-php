<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.literal(value)` / `z.literal([a, b])`: strict equality, with ints and floats compared as
 * JavaScript numbers.
 */
class ZodLiteral extends ZodType
{
    /** @var list<mixed> */
    protected array $literals;

    /** @param string|array<string, mixed>|null $params */
    public function __construct(mixed $value, string|array|null $params = null)
    {
        $this->literals = is_array($value) ? array_values($value) : [$value];
        if ($this->literals === []) {
            throw new \InvalidArgumentException('Cannot create literal schema with no valid values');
        }
        $this->withParams($params);
    }

    public function type(): string
    {
        return 'literal';
    }

    /** @return list<mixed> */
    public function values(): array
    {
        return $this->literals;
    }

    /** The single literal value (zod's `.value`). */
    public function value(): mixed
    {
        if (count($this->literals) > 1) {
            throw new \LogicException('This schema contains multiple valid literal values. Use `.values` instead.');
        }

        return $this->literals[0];
    }

    /** @return array<string, mixed> */
    public function def(): array
    {
        return parent::def() + ['values' => $this->literals];
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        if (Util::contains($this->literals, $payload->value)) {
            return $payload;
        }

        return $this->issue($payload, ['code' => 'invalid_value', 'values' => $this->literals]);
    }
}
