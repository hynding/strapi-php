<?php

declare(strict_types=1);

namespace Strapi\Core\Registries;

use Strapi\Types\Schema\Schema;

/** Port of packages/core/core/src/registries/components.ts. */
final class Components
{
    /** @var array<string, Schema> */
    private array $components = [];

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->components);
    }

    public function get(string $uid): ?Schema
    {
        return $this->components[$uid] ?? null;
    }

    /** @return array<string, Schema> */
    public function getAll(): array
    {
        return $this->components;
    }

    public function set(string $uid, Schema $component): static
    {
        if (array_key_exists($uid, $this->components)) {
            throw new \RuntimeException("Component {$uid} has already been registered.");
        }

        $this->components[$uid] = $component;

        return $this;
    }

    /** @param array<string, Schema> $newComponents */
    public function add(array $newComponents): void
    {
        foreach ($newComponents as $uid => $component) {
            $this->set((string) $uid, $component);
        }
    }

    /**
     * Replace a registered component (used by `convertCustomFieldType` and plugin extensions,
     * where upstream mutates the schema object in place).
     */
    public function replace(string $uid, Schema $component): static
    {
        $this->components[$uid] = $component;

        return $this;
    }
}
