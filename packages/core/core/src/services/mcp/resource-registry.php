<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp;

use Strapi\Core\Services\Mcp\Internal\McpCapabilityDefinitionRegistry;
use Strapi\Core\Services\Mcp\Internal\McpCapabilityRegistry;
use Strapi\Core\Services\Mcp\Metrics\WrapCapabilityHandlerForMetrics;
use Strapi\Core\Services\Mcp\Sdk\McpServer;
use Strapi\Core\Services\Mcp\Sdk\RegisteredCapability;
use Strapi\Core\Services\Mcp\Utils\CreateMcpCapabilityHandlerContext;
use Strapi\Core\Services\Mcp\Utils\CreateSafeCapabilityRegistration;
use Strapi\Core\Services\Mcp\Utils\ToSdkMcpCapabilityResult;
use Strapi\Core\Strapi;

/**
 * Port of services/mcp/resource-registry.ts (`McpResourceRegistry`, `makeMcpResourceDefinition`).
 *
 * A resource definition: `name`, `uri`, `metadata` (listing metadata), `telemetry?`,
 * `createHandler: fn(Strapi): fn(string $uri, extra)`, and `devModeOnly` or `auth`. The URI is
 * passed as a string (JS: a `URL`, whose `href` is that string).
 */
final class ResourceRegistry extends McpCapabilityRegistry
{
    public function __construct(private readonly Strapi $strapi, McpCapabilityDefinitionRegistry $definitions)
    {
        parent::__construct($definitions);
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public static function makeMcpResourceDefinition(array $definition): array
    {
        return $definition;
    }

    public function bind(McpServer $mcpServer): void
    {
        $strapi = $this->strapi;

        $this->register(static function (array $definition) use ($strapi, $mcpServer): RegisteredCapability {
            $name = (string) $definition['name'];
            $uri = (string) $definition['uri'];
            $metadata = is_array($definition['metadata'] ?? null) ? $definition['metadata'] : [];
            $telemetry = $definition['telemetry'] ?? null;

            return CreateSafeCapabilityRegistration::createSafeCapabilityRegistration([
                'strapi' => $strapi,
                'capabilityType' => 'Resource',
                'name' => $name,
                'createHandler' => $definition['createHandler'],
                'createFallbackHandler' => static fn (string $errorMessage): \Closure => static fn (string $resourceUri): array => [
                    'contents' => [['uri' => $resourceUri, 'text' => "Resource \"{$name}\" failed to initialize: {$errorMessage}", 'mimeType' => 'text/plain']],
                ],
                'createErrorResult' => static fn (\Throwable $error, array $args): array => [
                    'contents' => [['uri' => is_string($args[0] ?? null) ? $args[0] : $uri, 'text' => "Resource \"{$name}\" execution failed: {$error->getMessage()}", 'mimeType' => 'text/plain']],
                ],
                'registerWithSdk' => static function (\Closure $safeHandler) use ($strapi, $mcpServer, $name, $uri, $metadata, $telemetry): RegisteredCapability {
                    $sdkHandler = WrapCapabilityHandlerForMetrics::wrapCapabilityHandlerForMetrics(
                        $strapi,
                        'resource',
                        $name,
                        $telemetry,
                        static fn (string $uri, array $ctx): array => ToSdkMcpCapabilityResult::toSdkResourceReadResult(
                            $safeHandler($uri, CreateMcpCapabilityHandlerContext::createMcpCapabilityHandlerContext($ctx))
                        ),
                    );

                    return $mcpServer->registerResource($name, $uri, ToSdkMcpCapabilityResult::toSdkResourceListingMetadata($metadata), $sdkHandler);
                },
            ]);
        });
    }
}
