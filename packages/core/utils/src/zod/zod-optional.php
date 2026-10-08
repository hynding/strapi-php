<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `schema.optional()`: also accepts `undefined` (a missing object key).
 */
class ZodOptional extends ZodType
{
    public function __construct(protected ZodType $innerType)
    {
    }

    public function type(): string
    {
        return 'optional';
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

    public function isOptionalIn(): bool
    {
        return true;
    }

    public function isOptionalOut(): bool
    {
        return true;
    }

    /** @return list<mixed>|null */
    public function values(): ?array
    {
        $values = $this->innerType->values();

        return $values === null ? null : [...$values, Undefined::Value];
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        if ($this->innerType->isOptionalIn()) {
            $input = $payload->value;
            $result = $this->innerType->run($payload);
            if ($input === Undefined::Value && ($result->issues !== [] || $result->fallback)) {
                return new ParsePayload(Undefined::Value);
            }

            return $result;
        }
        if ($payload->value === Undefined::Value) {
            return $payload;
        }

        return $this->innerType->run($payload);
    }
}
