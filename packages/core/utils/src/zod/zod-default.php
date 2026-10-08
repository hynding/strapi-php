<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `schema.default(value)`: `undefined` input becomes `value` (returned as is, not parsed).
 * With `$prefault` (zod's `.prefault()`) the default is parsed by the inner schema.
 * A `\Closure` default is called each time.
 */
class ZodDefault extends ZodType
{
    public function __construct(protected ZodType $innerType, protected mixed $defaultValue, protected bool $prefault = false)
    {
    }

    public function type(): string
    {
        return $this->prefault ? 'prefault' : 'default';
    }

    public function unwrap(): ZodType
    {
        return $this->innerType;
    }

    /** @return array<string, mixed> */
    public function def(): array
    {
        return parent::def() + ['innerType' => $this->innerType, 'defaultValue' => $this->defaultValue()];
    }

    public function defaultValue(): mixed
    {
        return $this->defaultValue instanceof \Closure ? ($this->defaultValue)() : $this->defaultValue;
    }

    /** `removeDefault()` / `unwrap()` */
    public function removeDefault(): ZodType
    {
        return $this->innerType;
    }

    public function isOptionalIn(): bool
    {
        return true;
    }

    /** @return list<mixed>|null */
    public function values(): ?array
    {
        return $this->innerType->values();
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        if ($payload->value === Undefined::Value) {
            $payload->value = $this->defaultValue();
            if (!$this->prefault) {
                return $payload;
            }

            return $this->innerType->run($payload);
        }
        $result = $this->innerType->run($payload);
        if (!$this->prefault && $result->value === Undefined::Value) {
            $result->value = $this->defaultValue();
        }

        return $result;
    }
}
