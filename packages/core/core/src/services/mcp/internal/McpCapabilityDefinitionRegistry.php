<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Internal;

/**
 * Port of services/mcp/internal/McpCapabilityDefinitionRegistry.ts: the capability definitions
 * registered through `strapi.ai.mcp.registerTool/registerPrompt/registerResource`, by name.
 *
 * A definition is an array with at least `name` and either `devModeOnly: true` or
 * `auth: { policies: [{ action, subject? }, ...] }` (Modules.MCP.McpCapabilityDefinition).
 */
final class McpCapabilityDefinitionRegistry implements \Countable
{
    /** @var array<string, array<string, mixed>> */
    private array $definitions = [];

    /** @param 'tool'|'prompt'|'resource' $capability */
    public function __construct(public readonly string $capability)
    {
    }

    /** `size` */
    /** @return int<0, max> */
    public function size(): int
    {
        return count($this->definitions);
    }

    /** @return int<0, max> */
    public function count(): int
    {
        return $this->size();
    }

    /** @param array<string, mixed> $definition */
    public function define(array $definition): void
    {
        $name = (string) ($definition['name'] ?? '');
        if (isset($this->definitions[$name])) {
            throw new \RuntimeException("[MCP] {$this->capability} with name \"{$name}\" is already registered. Names must be unique.");
        }

        if (($definition['devModeOnly'] ?? null) !== true) {
            $policies = $definition['auth']['policies'] ?? null;
            $invalid = !is_array($policies) || $policies === [];
            foreach (is_array($policies) ? $policies : [] as $policy) {
                $invalid = $invalid || !is_array($policy) || ($policy['action'] ?? '') === '';
            }
            if ($invalid) {
                throw new \RuntimeException("[MCP] {$this->capability} with name \"{$name}\" must declare auth policies or be devModeOnly.");
            }
        }

        $this->definitions[$name] = $definition;
    }

    /** @return array<string, mixed>|null */
    public function get(string $name): ?array
    {
        return $this->definitions[$name] ?? null;
    }

    public function delete(string $name): bool
    {
        if (!isset($this->definitions[$name])) {
            return false;
        }
        unset($this->definitions[$name]);

        return true;
    }

    /** @return list<array<string, mixed>> */
    public function getAll(): array
    {
        return array_values($this->definitions);
    }

    /** @param callable(array<string, mixed>): void $callback */
    public function forEach(callable $callback): void
    {
        foreach ($this->definitions as $definition) {
            $callback($definition);
        }
    }
}
