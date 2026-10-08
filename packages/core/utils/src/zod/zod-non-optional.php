<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `schema.nonoptional()`: rejects `undefined` output (`expected: "nonoptional"`).
 */
class ZodNonOptional extends ZodType
{
    /** @param string|array<string, mixed>|null $params */
    public function __construct(protected ZodType $innerType, string|array|null $params = null)
    {
        $this->withParams($params);
    }

    public function type(): string
    {
        return 'nonoptional';
    }

    public function unwrap(): ZodType
    {
        return $this->innerType;
    }

    /** @return array<string, mixed> */
    public function def(): array
    {
        return parent::def() + ['innerType' => $this->innerType];
    }

    /** @return list<mixed>|null */
    public function values(): ?array
    {
        $values = $this->innerType->values();

        return $values === null ? null : array_values(array_filter($values, static fn (mixed $v): bool => $v !== Undefined::Value));
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        $result = $this->innerType->run($payload);
        if ($result->issues === [] && $result->value === Undefined::Value) {
            $result->issues[] = ['code' => 'invalid_type', 'expected' => 'nonoptional', 'input' => Undefined::Value, 'inst' => $this];
        }

        return $result;
    }
}
