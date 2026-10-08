<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `schema.readonly()`: no runtime effect beyond the inner schema (PHP values are not frozen);
 * marks the JSON Schema `readOnly`.
 */
class ZodReadonly extends ZodType
{
    public function __construct(protected ZodType $innerType)
    {
    }

    public function type(): string
    {
        return 'readonly';
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
        return $this->innerType->values();
    }

    /** @return array<string, list<mixed>>|null */
    public function propValues(): ?array
    {
        return $this->innerType->propValues();
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        return $this->innerType->run($payload);
    }
}
