<?php

declare(strict_types=1);

namespace Strapi\Core\Registries;

use Strapi\Utils\Primitives\Objects;

/**
 * Port of packages/core/core/src/registries/sanitizers.ts: a tree of sanitizer lists keyed by path
 * (`content-api.input`, `content-api.output`, `content-api.query`).
 */
final class Sanitizers
{
    /** @var array<string, mixed> */
    private array $sanitizers = [];

    /** @return list<callable> */
    public function get(string $path): array
    {
        $value = Objects::get($this->sanitizers, $path, []);

        return is_array($value) ? array_values($value) : [];
    }

    public function add(string $path, callable $sanitizer): static
    {
        $list = $this->get($path);
        $list[] = $sanitizer;
        $this->sanitizers = Objects::set($this->sanitizers, $path, $list);

        return $this;
    }

    public function set(string $path, mixed $value = []): static
    {
        $this->sanitizers = Objects::set($this->sanitizers, $path, $value);

        return $this;
    }

    public function has(string $path): bool
    {
        return Objects::has($this->sanitizers, $path);
    }
}
