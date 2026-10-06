<?php

declare(strict_types=1);

namespace Strapi\Permissions\Engine\Hooks;

/**
 * `{ ...options, get permission(), condition: { and(), or() } }` handed to the before-register hook.
 * The permission here is the CASL-style rule (`{ action, subject, properties, condition }`) and
 * `condition->and()` / `condition->or()` mutate it in place.
 *
 * @implements \ArrayAccess<string, mixed>
 */
final class WillRegisterContext implements \ArrayAccess
{
    /** @var array<string, mixed> */
    private array $permission;

    public readonly ConditionBuilder $condition;

    /**
     * @param array<string, mixed> $permission
     * @param array<string, mixed> $options
     */
    public function __construct(array &$permission, private readonly array $options = [])
    {
        $this->permission = &$permission;
        $this->condition = new ConditionBuilder($permission);
    }

    /** @return array<string, mixed> a copy of the rule being registered */
    public function permission(): array
    {
        return $this->permission;
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        return $this->options;
    }

    public function __get(string $name): mixed
    {
        if ($name === 'permission') {
            return $this->permission;
        }

        return $this->options[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return $name === 'permission' || isset($this->options[$name]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return in_array($offset, ['permission', 'condition'], true) || array_key_exists($offset, $this->options);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return match ($offset) {
            'permission' => $this->permission,
            'condition' => $this->condition,
            default => $this->options[$offset] ?? null,
        };
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('The will-register context is read-only');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('The will-register context is read-only');
    }
}
