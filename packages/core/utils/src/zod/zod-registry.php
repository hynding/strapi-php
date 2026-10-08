<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.registry()`: metadata keyed by schema identity. A registry whose entries carry an `id` can be
 * passed to `z.toJSONSchema()` (one JSON Schema per id, cross-references as `$ref`) or used as
 * its `metadata` param.
 */
final class ZodRegistry
{
    /** @var \SplObjectStorage<ZodType, array<string, mixed>> */
    private \SplObjectStorage $entries;

    public function __construct()
    {
        $this->entries = new \SplObjectStorage();
    }

    /** @param array<string, mixed> $meta */
    public function add(ZodType $schema, array $meta = []): self
    {
        if (isset($meta['id']) && is_string($meta['id'])) {
            foreach ($this->entries as $existing) {
                if ($existing !== $schema && ($this->entries[$existing]['id'] ?? null) === $meta['id']) {
                    throw new \LogicException("ID {$meta['id']} already exists in the registry");
                }
            }
        }
        $this->entries[$schema] = $meta;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function get(ZodType $schema): ?array
    {
        return $this->entries->contains($schema) ? $this->entries[$schema] : null;
    }

    public function has(ZodType $schema): bool
    {
        return $this->entries->contains($schema);
    }

    public function remove(ZodType $schema): self
    {
        $this->entries->detach($schema);

        return $this;
    }

    public function clear(): self
    {
        $this->entries = new \SplObjectStorage();

        return $this;
    }

    /** @return list<array{ZodType, array<string, mixed>}> */
    public function all(): array
    {
        $all = [];
        foreach ($this->entries as $schema) {
            $all[] = [$schema, $this->entries[$schema]];
        }

        return $all;
    }
}
