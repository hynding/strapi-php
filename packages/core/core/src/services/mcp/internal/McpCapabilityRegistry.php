<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Internal;

use Strapi\Core\Services\Mcp\Sdk\McpServer;
use Strapi\Core\Services\Mcp\Sdk\RegisteredCapability;

/**
 * Port of services/mcp/internal/McpCapabilityRegistry.ts: `McpCapabilityRegistryBase` (the
 * `McpCapabilityRegistry` interface is its abstract `bind()`). The capabilities bound to one
 * session's McpServer, each disabled until syncMcpSessionCapabilities enables it.
 */
abstract class McpCapabilityRegistry
{
    /** @var array<string, RegisteredCapability> */
    private array $registered = [];

    public function __construct(private readonly McpCapabilityDefinitionRegistry $definitions)
    {
    }

    abstract public function bind(McpServer $mcpServer): void;

    /** @param callable(array<string, mixed>): RegisteredCapability $registerFn */
    protected function register(callable $registerFn): void
    {
        $this->definitions->forEach(function (array $definition) use ($registerFn): void {
            $name = (string) $definition['name'];
            // Defence: register() must be called at most once per registry instance (double-bind protection).
            if (isset($this->registered[$name])) {
                throw new \RuntimeException("[MCP] {$this->definitions->capability} with name \"{$name}\" is already registered. Names must be unique.");
            }

            $registered = $registerFn($definition);

            // Disable the capability until explicitly enabled depending on devModeOnly and authorization
            $registered->disable();

            $this->registered[$name] = $registered;
        });
    }

    /**
     * @param array{filter?: array{status?: list<'enabled'|'disabled'|'defined'|'undefined'>}} $ctx
     * @return list<array{name: string, status: string, devModeOnly: bool, auth: mixed}>
     */
    public function list(array $ctx = []): array
    {
        $statuses = $ctx['filter']['status'] ?? null;
        $out = [];
        foreach ($this->definitions->getAll() as $definition) {
            $name = (string) $definition['name'];
            $status = $this->status($name);
            if ($statuses !== null && !in_array($status, $statuses, true)) {
                continue;
            }
            $out[] = ['name' => $name, 'status' => $status, 'devModeOnly' => (bool) ($definition['devModeOnly'] ?? false), 'auth' => $definition['auth'] ?? null];
        }

        return $out;
    }

    /** @return 'enabled'|'disabled'|'defined'|'undefined' */
    public function status(string $name): string
    {
        $registered = $this->registered[$name] ?? null;
        if ($registered !== null) {
            return $registered->enabled === true ? 'enabled' : 'disabled';
        }
        if ($this->definitions->get($name) !== null) {
            return 'defined';
        }

        return 'undefined';
    }

    /** @internal Used by syncMcpSessionCapabilities; not part of the public registry API. */
    public function enable(string $name): void
    {
        $this->registeredOrFail($name)->enable();
    }

    /** @internal */
    public function enableAll(): void
    {
        foreach ($this->registered as $registered) {
            $registered->enable();
        }
    }

    /** @internal */
    public function disable(string $name): void
    {
        $this->registeredOrFail($name)->disable();
    }

    /** @internal */
    public function disableAll(): void
    {
        foreach ($this->registered as $registered) {
            $registered->disable();
        }
    }

    /** @internal */
    public function remove(string $name): void
    {
        $this->registeredOrFail($name)->remove();
        unset($this->registered[$name]);
    }

    /** @internal */
    public function removeAll(): void
    {
        foreach ($this->registered as $registered) {
            $registered->remove();
        }
        $this->registered = [];
    }

    private function registeredOrFail(string $name): RegisteredCapability
    {
        return $this->registered[$name] ?? throw new \RuntimeException("[MCP] {$this->definitions->capability} with name \"{$name}\" is not registered.");
    }
}
