<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `schema.nullable()`: also accepts `null`.
 */
class ZodNullable extends ZodType
{
    public function __construct(protected ZodType $innerType)
    {
    }

    public function type(): string
    {
        return 'nullable';
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
        return $this->innerType->isOptionalIn();
    }

    public function isOptionalOut(): bool
    {
        return $this->innerType->isOptionalOut();
    }

    /** @return list<mixed>|null */
    public function values(): ?array
    {
        $values = $this->innerType->values();

        return $values === null ? null : [...$values, null];
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        if ($payload->value === null) {
            return $payload;
        }

        return $this->innerType->run($payload);
    }
}
