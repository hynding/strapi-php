<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp;

use Strapi\Core\Services\Mcp\Handlers\HandlePost;
use Strapi\Core\Services\Mcp\Internal\McpCapabilityDefinitionRegistry;
use Strapi\Core\Services\Mcp\Internal\McpConfiguration;
use Strapi\Core\Services\Mcp\Internal\McpServerFactory;
use Strapi\Core\Services\Mcp\Metrics\Metrics;
use Strapi\Core\Services\Mcp\Middleware\OauthDiscoveryFallback;
use Strapi\Core\Services\Mcp\Tools\Log;
use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/services/mcp/index.ts (`createMcpService`): `strapi.ai.mcp`.
 *
 * Capabilities are registered (`registerTool`, `registerPrompt`, `registerResource`) before the
 * server starts; `start()` (provider bootstrap, when `server.mcp.enabled`) mounts the OAuth
 * discovery fallback middleware and `POST|GET|DELETE|PUT|PATCH /mcp`. Each POST builds an
 * McpServer for the admin token's ability (stateless Streamable HTTP, JSON-RPC 2.0); the MCP SDK
 * subset it runs on is sdk/ (not an upstream file).
 *
 * See the core README for the definition shapes (arrays with closures).
 */
final class Mcp
{
    /** @var 'idle'|'starting'|'running'|'stopping'|'error' */
    private string $serverStatus = 'idle';

    private readonly McpConfiguration $config;

    private readonly McpCapabilityDefinitionRegistry $toolDefinitions;

    private readonly McpCapabilityDefinitionRegistry $promptDefinitions;

    private readonly McpCapabilityDefinitionRegistry $resourceDefinitions;

    /** @var \Closure(\Strapi\Core\Services\Server\Context): void */
    private readonly \Closure $handlePost;

    public function __construct(private readonly Strapi $strapi)
    {
        // Initialize configuration
        $this->config = new McpConfiguration($strapi);

        $authenticationStrategy = Authentication::createMcpAdminTokenAuthenticator($strapi);

        // Definition registries
        $this->toolDefinitions = new McpCapabilityDefinitionRegistry('tool');
        $this->promptDefinitions = new McpCapabilityDefinitionRegistry('prompt');
        $this->resourceDefinitions = new McpCapabilityDefinitionRegistry('resource');

        // Create HTTP handlers
        $this->handlePost = HandlePost::createPostHandler([
            'strapi' => $strapi,
            'authenticationStrategy' => $authenticationStrategy,
            'config' => $this->config,
            'createServerWithRegistries' => McpServerFactory::createMcpServerWithRegistries(...),
            'capabilityDefinitions' => $this->capabilityDefinitions(),
        ]);

        $this->registerTool(Log::logToolDefinition());
    }

    /** Creates an MCP service instance for Strapi Core */
    public static function createMcpService(Strapi $strapi): self
    {
        return new self($strapi);
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function isRunning(): bool
    {
        return $this->serverStatus === 'running';
    }

    /** @param array<string, mixed> $tool */
    public function registerTool(array $tool): void
    {
        if ($this->serverStatus !== 'idle') {
            throw new \RuntimeException('[MCP] Cannot register tools after the MCP server has started.');
        }

        $this->toolDefinitions->define($tool);
    }

    /** @param array<string, mixed> $prompt */
    public function registerPrompt(array $prompt): void
    {
        if ($this->serverStatus !== 'idle') {
            throw new \RuntimeException('[MCP] Cannot register prompts after the MCP server has started.');
        }
        $this->promptDefinitions->define($prompt);
    }

    /** @param array<string, mixed> $resource */
    public function registerResource(array $resource): void
    {
        if ($this->serverStatus !== 'idle') {
            throw new \RuntimeException('[MCP] Cannot register resources after the MCP server has started.');
        }
        $this->resourceDefinitions->define($resource);
    }

    public function start(): void
    {
        if ($this->isEnabled() === false) {
            $this->strapi->log()->debug('[MCP] Server is disabled');

            return;
        }
        if ($this->serverStatus === 'error') {
            throw new \RuntimeException('[MCP] Cannot start server: previous error state');
        }
        if ($this->serverStatus !== 'idle') {
            throw new \RuntimeException("[MCP] Server already started or starting (status: {$this->serverStatus})");
        }
        $this->serverStatus = 'starting';

        $this->strapi->server()->use(OauthDiscoveryFallback::createOAuthDiscoveryFallbackMiddleware());

        $routes = Routes::createMcpRoutes($this->config, ['handlePost' => $this->handlePost]);
        $this->strapi->server()->routes($routes);

        $this->serverStatus = 'running';

        Metrics::sendDidStartMcpServer($this->strapi, [
            'path' => $this->config->path,
            'numberOfTools' => $this->toolDefinitions->size(),
            'numberOfPrompts' => $this->promptDefinitions->size(),
            'numberOfResources' => $this->resourceDefinitions->size(),
        ]);

        $baseUrl = $this->strapi->config()->get('server.url', 'http://localhost:1337');
        $this->strapi->log()->info('[MCP] Server available at ' . (is_string($baseUrl) ? $baseUrl : '') . $this->config->path);
    }

    public function stop(): void
    {
        $this->serverStatus = 'idle';
        $this->strapi->log()->info('[MCP] Service stopped');
    }

    /** @return array{tools: McpCapabilityDefinitionRegistry, prompts: McpCapabilityDefinitionRegistry, resources: McpCapabilityDefinitionRegistry} */
    public function capabilityDefinitions(): array
    {
        return ['tools' => $this->toolDefinitions, 'prompts' => $this->promptDefinitions, 'resources' => $this->resourceDefinitions];
    }

    public function config(): McpConfiguration
    {
        return $this->config;
    }
}
