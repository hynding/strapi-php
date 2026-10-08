<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\Nexus\Definitions;

/**
 * nexus `plugin({ name, onAddOutputField, onAddInputField, onAddArg })`. The hooks receive the
 * field (or argument) config array and may return a replacement, as nexus' hooks do.
 */
final class PluginDef
{
    public readonly string $name;

    /** @param array<string, mixed> $config */
    public function __construct(public readonly array $config)
    {
        $this->name = is_string($config['name'] ?? null) ? $config['name'] : '';
    }

    public function hook(string $name): ?callable
    {
        $hook = $this->config[$name] ?? null;

        return is_callable($hook) ? $hook : null;
    }
}
