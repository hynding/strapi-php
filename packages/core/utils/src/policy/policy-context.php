<?php

declare(strict_types=1);

namespace Strapi\Utils\Policy;

/**
 * Policy context: `{ type, is(type), ...ctx }`. Extra context keys are exposed as properties
 * and via array access (`$ctx['state']`), mirroring `Object.assign({ is, type }, ctx)`.
 *
 * @implements \ArrayAccess<string, mixed>
 */
final class PolicyContext implements \ArrayAccess
{
    /** @param array<string, mixed> $ctx */
    public function __construct(public readonly string $type, private array $ctx)
    {
    }

    public function is(mixed $otherType): bool
    {
        return $this->type === $otherType;
    }

    public function __get(string $name): mixed
    {
        return $this->ctx[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->ctx[$name]);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->ctx[$name] = $value;
    }

    public function offsetExists(mixed $offset): bool
    {
        return $offset === 'type' || array_key_exists($offset, $this->ctx);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $offset === 'type' ? $this->type : ($this->ctx[$offset] ?? null);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->ctx[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->ctx[$offset]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['type' => $this->type] + $this->ctx;
    }
}
