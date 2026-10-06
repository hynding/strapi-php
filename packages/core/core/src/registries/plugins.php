<?php

declare(strict_types=1);

namespace Strapi\Core\Registries;

use Strapi\Core\Domain\Module\Module;
use Strapi\Core\Strapi;

/** Port of packages/core/core/src/registries/plugins.ts. */
final class Plugins
{
    /** @var array<string, Module> */
    private array $plugins = [];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function get(string $name): ?Module
    {
        return $this->plugins[$name] ?? null;
    }

    /** @return array<string, Module> */
    public function getAll(): array
    {
        return $this->plugins;
    }

    /** @param array<string, mixed> $pluginConfig */
    public function add(string $name, array $pluginConfig): Module
    {
        if (array_key_exists($name, $this->plugins)) {
            throw new \RuntimeException("Plugin {$name} has already been registered.");
        }

        $pluginModule = $this->strapi->get('modules')->add("plugin::{$name}", $pluginConfig);
        $this->plugins[$name] = $pluginModule;

        return $pluginModule;
    }
}
