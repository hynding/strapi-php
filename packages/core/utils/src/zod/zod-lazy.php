<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.lazy(fn () => schema)` for recursive schemas; the getter runs once.
 */
class ZodLazy extends ZodType
{
    private ?ZodType $resolved = null;

    /** @param \Closure(): ZodType $getter */
    public function __construct(protected \Closure $getter)
    {
    }

    public function type(): string
    {
        return 'lazy';
    }

    public function unwrap(): ZodType
    {
        return $this->resolved ??= ($this->getter)();
    }

    /** @return array<string, mixed> */
    public function def(): array
    {
        return parent::def() + ['getter' => $this->getter];
    }

    public function isOptionalIn(): bool
    {
        return $this->unwrap()->isOptionalIn();
    }

    public function isOptionalOut(): bool
    {
        return $this->unwrap()->isOptionalOut();
    }

    /** @return array<string, list<mixed>>|null */
    public function propValues(): ?array
    {
        return $this->unwrap()->propValues();
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        return $this->unwrap()->run($payload);
    }
}
