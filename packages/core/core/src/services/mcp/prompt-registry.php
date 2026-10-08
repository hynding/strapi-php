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
 * Port of services/mcp/prompt-registry.ts (`McpPromptRegistry`, `makeMcpPromptDefinition`).
 *
 * A prompt definition: `name`, `title`, `description`, `argsSchema?: ZodObject`, `telemetry?`,
 * `createHandler: fn(Strapi): fn(args, extra) | fn(extra)`, and `devModeOnly` or `auth`.
 */
final class PromptRegistry extends McpCapabilityRegistry
{
    public function __construct(private readonly Strapi $strapi, McpCapabilityDefinitionRegistry $definitions)
    {
        parent::__construct($definitions);
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public static function makeMcpPromptDefinition(array $definition): array
    {
        return $definition;
    }

    public function bind(McpServer $mcpServer): void
    {
        $strapi = $this->strapi;

        $this->register(static function (array $definition) use ($strapi, $mcpServer): RegisteredCapability {
            $name = (string) $definition['name'];
            $argsSchema = $definition['argsSchema'] ?? null;
            $telemetry = $definition['telemetry'] ?? null;
            $errorMessages = static fn (string $text): array => ['messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]]];

            return CreateSafeCapabilityRegistration::createSafeCapabilityRegistration([
                'strapi' => $strapi,
                'capabilityType' => 'Prompt',
                'name' => $name,
                'createHandler' => $definition['createHandler'],
                'createFallbackHandler' => static fn (string $errorMessage): \Closure => static fn (): array => $errorMessages("Prompt \"{$name}\" failed to initialize: {$errorMessage}"),
                'createErrorResult' => static fn (\Throwable $error): array => $errorMessages("Prompt \"{$name}\" execution failed: {$error->getMessage()}"),
                'registerWithSdk' => static function (\Closure $safeHandler) use ($strapi, $mcpServer, $name, $definition, $argsSchema, $telemetry): RegisteredCapability {
                    $config = ['title' => $definition['title'] ?? null, 'description' => $definition['description'] ?? null];

                    if ($argsSchema === null) {
                        $sdkHandler = WrapCapabilityHandlerForMetrics::wrapCapabilityHandlerForMetrics(
                            $strapi,
                            'prompt',
                            $name,
                            $telemetry,
                            static fn (array $context): array => ToSdkMcpCapabilityResult::toSdkPromptResult($safeHandler($context)),
                        );

                        return $mcpServer->registerPrompt($name, $config, static fn (array $ctx): array => $sdkHandler(CreateMcpCapabilityHandlerContext::createMcpCapabilityHandlerContext($ctx)));
                    }

                    $sdkHandler = WrapCapabilityHandlerForMetrics::wrapCapabilityHandlerForMetrics(
                        $strapi,
                        'prompt',
                        $name,
                        $telemetry,
                        static fn (mixed $args, array $context): array => ToSdkMcpCapabilityResult::toSdkPromptResult($safeHandler($args, $context)),
                    );

                    return $mcpServer->registerPrompt(
                        $name,
                        [...$config, 'argsSchema' => $argsSchema],
                        static fn (mixed $args, array $ctx): array => $sdkHandler($args, CreateMcpCapabilityHandlerContext::createMcpCapabilityHandlerContext($ctx)),
                    );
                },
            ]);
        });
    }
}
