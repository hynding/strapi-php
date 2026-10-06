<?php

declare(strict_types=1);

namespace Strapi\Core\Registries;

use Strapi\Core\Domain\Module\Module;
use Strapi\Core\Strapi;

/** Port of packages/core/core/src/registries/modules.ts. */
final class Modules
{
    /** @var array<string, Module> */
    private array $modules = [];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function get(string $namespace): ?Module
    {
        return $this->modules[$namespace] ?? null;
    }

    /** @return array<string, Module> */
    public function getAll(string $prefix = ''): array
    {
        return array_filter($this->modules, static fn (string $namespace): bool => str_starts_with($namespace, $prefix), ARRAY_FILTER_USE_KEY);
    }

    /** @param array<string, mixed> $rawModule */
    public function add(string $namespace, array $rawModule): Module
    {
        if (array_key_exists($namespace, $this->modules)) {
            throw new \RuntimeException("Module {$namespace} has already been registered.");
        }

        $this->modules[$namespace] = Module::createModule($namespace, $rawModule, $this->strapi);
        $this->modules[$namespace]->load();

        return $this->modules[$namespace];
    }

    public function bootstrap(): void
    {
        foreach ($this->modules as $mod) {
            $mod->bootstrap();
        }
    }

    public function register(): void
    {
        foreach ($this->modules as $mod) {
            $mod->register();
        }
    }

    public function destroy(): void
    {
        foreach ($this->modules as $mod) {
            $mod->destroy();
        }
    }
}
