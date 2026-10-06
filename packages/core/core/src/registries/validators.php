<?php

declare(strict_types=1);

namespace Strapi\Core\Registries;

use Strapi\Utils\Primitives\Objects;

/** Port of packages/core/core/src/registries/validators.ts (same shape as {@see Sanitizers}). */
final class Validators
{
    /** @var array<string, mixed> */
    private array $validators = [];

    /** @return list<callable> */
    public function get(string $path): array
    {
        $value = Objects::get($this->validators, $path, []);

        return is_array($value) ? array_values($value) : [];
    }

    public function add(string $path, callable $validator): static
    {
        $list = $this->get($path);
        $list[] = $validator;
        $this->validators = Objects::set($this->validators, $path, $list);

        return $this;
    }

    public function set(string $path, mixed $value = []): static
    {
        $this->validators = Objects::set($this->validators, $path, $value);

        return $this;
    }

    public function has(string $path): bool
    {
        return Objects::has($this->validators, $path);
    }
}
