<?php

declare(strict_types=1);

namespace Strapi\Core\Registries;

use Strapi\Utils\Hooks\Hook;

/** Port of packages/core/core/src/registries/hooks.ts. */
final class Hooks
{
    /** @var array<string, Hook> */
    private array $hooks = [];

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->hooks);
    }

    public function get(string $uid): ?Hook
    {
        return $this->hooks[$uid] ?? null;
    }

    /** @return array<string, Hook> */
    public function getAll(string $namespace = ''): array
    {
        return array_filter($this->hooks, static fn (string $uid): bool => Namespace_::hasNamespace($uid, $namespace), ARRAY_FILTER_USE_KEY);
    }

    public function set(string $uid, Hook $hook): static
    {
        $this->hooks[$uid] = $hook;

        return $this;
    }

    /** @param array<string, Hook> $hooks */
    public function add(string $namespace, array $hooks): static
    {
        foreach ($hooks as $hookName => $hook) {
            $this->set(Namespace_::addNamespace((string) $hookName, $namespace), $hook);
        }

        return $this;
    }

    /** @param callable(Hook): Hook $extendFn */
    public function extend(string $uid, callable $extendFn): static
    {
        $currentHook = $this->get($uid);

        if ($currentHook === null) {
            throw new \RuntimeException("Hook {$uid} doesn't exist");
        }

        $this->hooks[$uid] = $extendFn($currentHook);

        return $this;
    }
}
