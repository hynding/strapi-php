<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services;

use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/services/type-registry.ts. The service (`{ new: createTypeRegistry }`) and
 * the registry it creates are this class: `getService('type-registry')->new()` returns a fresh,
 * empty registry.
 *
 * @phpstan-type RegisteredTypeDef array{name: string, definition: mixed, config: array<string, mixed>}
 */
final class TypeRegistry
{
    /** @var array<string, RegisteredTypeDef> */
    private array $registry = [];

    /** Create a new type registry */
    public function new(): self
    {
        return new self();
    }

    /**
     * Register a new type definition
     *
     * @param array<string, mixed> $config
     */
    public function register(string $name, mixed $definition, array $config = []): self
    {
        if (array_key_exists($name, $this->registry)) {
            throw new ApplicationError("\"{$name}\" has already been registered");
        }

        $this->registry[$name] = ['name' => $name, 'definition' => $definition, 'config' => $config];

        return $this;
    }

    /**
     * Register many types definitions at once
     *
     * @param iterable<string, mixed> $definitionsEntries name => definition
     * @param array<string, mixed>|callable(string, mixed): array<string, mixed> $config
     */
    public function registerMany(iterable $definitionsEntries, array|callable $config = []): self
    {
        foreach ($definitionsEntries as $name => $definition) {
            $this->register((string) $name, $definition, is_callable($config) ? $config((string) $name, $definition) : $config);
        }

        return $this;
    }

    /** Check if the given type name has already been added to the registry */
    public function has(string $name): bool
    {
        return array_key_exists($name, $this->registry);
    }

    /**
     * Get the type definition for `name`
     *
     * @return RegisteredTypeDef|null
     */
    public function get(mixed $name): ?array
    {
        return is_string($name) ? ($this->registry[$name] ?? null) : null;
    }

    /**
     * Transform and return the registry as an object
     *
     * @return array<string, RegisteredTypeDef>
     */
    public function toObject(): array
    {
        return $this->registry;
    }

    /**
     * Return the name of every registered type
     *
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->registry);
    }

    /**
     * Return all the registered definitions as an array
     *
     * @return list<RegisteredTypeDef>
     */
    public function definitions(): array
    {
        return array_values($this->registry);
    }

    /**
     * Filter and return the types definitions that matches the given predicate
     *
     * @param callable(RegisteredTypeDef): bool $predicate
     * @return list<RegisteredTypeDef>
     */
    public function where(callable $predicate): array
    {
        return array_values(array_filter($this->definitions(), $predicate));
    }
}
