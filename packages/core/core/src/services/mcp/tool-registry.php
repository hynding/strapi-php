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
use Strapi\Utils\Zod\ZodType;

/**
 * Port of services/mcp/tool-registry.ts (`McpToolRegistry`, `makeMcpToolDefinition`).
 *
 * A tool definition is an array: `name`, `title`, `description`, `telemetry?`,
 * `resolveInputSchema?: fn(context): ZodObject`, `resolveOutputSchema: fn(context): ZodObject`,
 * `createHandler: fn(Strapi, context): fn(['args' => ..., 'extra' => ...]): result`, and either
 * `devModeOnly: true` or `auth: ['policies' => [['action' => ..., 'subject' => ...?], ...]]`.
 * The handler context is `['userAbility' => Ability, 'user' => ['id' => ...]]`.
 */
final class ToolRegistry extends McpCapabilityRegistry
{
    public function __construct(
        private readonly Strapi $strapi,
        McpCapabilityDefinitionRegistry $definitions,
        private readonly \Strapi\Permissions\Engine\Abilities\Ability $ability,
        private readonly mixed $user,
    ) {
        parent::__construct($definitions);
    }

    /**
     * Defines a Strapi MCP tool, ready to pass to `strapi.ai.mcp.registerTool()` (exposed publicly
     * as `ai.mcp.defineTool`, see src/mcp.php). The definition is returned unchanged.
     *
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public static function makeMcpToolDefinition(array $definition): array
    {
        return $definition;
    }

    public function bind(McpServer $mcpServer): void
    {
        $strapi = $this->strapi;

        $this->register(function (array $definition) use ($strapi, $mcpServer): RegisteredCapability {
            $name = (string) $definition['name'];
            $resolveInputSchema = $definition['resolveInputSchema'] ?? null;
            $resolveOutputSchema = $definition['resolveOutputSchema'];
            $createHandler = $definition['createHandler'];
            $telemetry = $definition['telemetry'] ?? null;

            // Bind the session ability and token owner into the handler context so handlers can enforce
            // field-level and entity-level permission checks and set creator fields.
            $context = ['userAbility' => $this->ability, 'user' => $this->user];

            return CreateSafeCapabilityRegistration::createSafeCapabilityRegistration([
                'strapi' => $strapi,
                'capabilityType' => 'Tool',
                'name' => $name,
                'createHandler' => static fn (Strapi $strapi): callable => $createHandler($strapi, $context),
                'createFallbackHandler' => static fn (string $errorMessage): \Closure => static fn (): array => [
                    'content' => [['type' => 'text', 'text' => "Tool \"{$name}\" failed to initialize: {$errorMessage}"]],
                    'isError' => true,
                ],
                'createErrorResult' => static fn (\Throwable $error): array => [
                    'content' => [['type' => 'text', 'text' => "Tool \"{$name}\" execution failed: {$error->getMessage()}"]],
                    'isError' => true,
                ],
                'registerWithSdk' => static function (\Closure $safeHandler) use ($strapi, $mcpServer, $name, $definition, $resolveInputSchema, $resolveOutputSchema, $telemetry, $context): RegisteredCapability {
                    $inputSchema = $resolveInputSchema !== null ? $resolveInputSchema($context) : null;
                    $outputSchema = $resolveOutputSchema($context);
                    if ($inputSchema !== null && !$inputSchema instanceof ZodType) {
                        throw new \InvalidArgumentException('resolveInputSchema must return a Zod schema');
                    }
                    if (!$outputSchema instanceof ZodType) {
                        throw new \InvalidArgumentException('resolveOutputSchema must return a Zod schema');
                    }
                    // the SDK converts the schemas when listing; convert once now so a schema it
                    // cannot advertise fails here (registration fault isolation, Level 3)
                    if ($inputSchema !== null) {
                        McpServer::standardSchemaToJsonSchema($inputSchema, 'input');
                    }
                    McpServer::standardSchemaToJsonSchema($outputSchema, 'output');

                    $config = ['title' => $definition['title'] ?? null, 'description' => $definition['description'] ?? null, 'outputSchema' => $outputSchema];

                    if ($inputSchema !== null) {
                        // Adapt from Strapi's object parameter to the SDK's positional callback.
                        $sdkHandler = WrapCapabilityHandlerForMetrics::wrapCapabilityHandlerForMetrics(
                            $strapi,
                            'tool',
                            $name,
                            $telemetry,
                            static fn (mixed $args, array $ctx): array => ToSdkMcpCapabilityResult::toSdkToolResult(
                                $safeHandler(['args' => $args, 'extra' => CreateMcpCapabilityHandlerContext::createMcpCapabilityHandlerContext($ctx)])
                            ),
                        );

                        return $mcpServer->registerTool($name, [...$config, 'inputSchema' => $inputSchema], $sdkHandler);
                    }

                    $sdkHandler = WrapCapabilityHandlerForMetrics::wrapCapabilityHandlerForMetrics(
                        $strapi,
                        'tool',
                        $name,
                        $telemetry,
                        static fn (array $ctx): array => ToSdkMcpCapabilityResult::toSdkToolResult(
                            $safeHandler(['extra' => CreateMcpCapabilityHandlerContext::createMcpCapabilityHandlerContext($ctx)])
                        ),
                    );

                    return $mcpServer->registerTool($name, $config, $sdkHandler);
                },
            ]);
        });
    }
}
