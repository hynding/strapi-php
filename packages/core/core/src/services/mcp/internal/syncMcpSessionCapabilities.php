<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Internal;

/** Port of services/mcp/internal/syncMcpSessionCapabilities.ts. */
final class SyncMcpSessionCapabilities
{
    /** @param array{policies: list<array{action: string, subject?: string|null}>} $auth */
    private static function canUseAuthorizedCapability(\Strapi\Permissions\Engine\Abilities\Ability $ability, array $auth): bool
    {
        foreach ($auth['policies'] as $policy) {
            $subject = $policy['subject'] ?? null;
            $allowed = $subject !== null ? $ability->can($policy['action'], $subject) : $ability->can($policy['action']);
            if ($allowed === true) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $definition */
    public static function canUseMcpCapability(\Strapi\Permissions\Engine\Abilities\Ability $ability, array $definition, bool $isDevMode): bool
    {
        if (($definition['devModeOnly'] ?? null) === true) {
            return $isDevMode === true;
        }

        if (!isset($definition['auth'])) {
            return false;
        }

        // Session capability availability is a coarse allowlist. Field/entity conditions must be
        // enforced by capability handlers with request-specific context.
        return self::canUseAuthorizedCapability($ability, $definition['auth']);
    }

    /**
     * @param array{tools: McpCapabilityRegistry, prompts: McpCapabilityRegistry, resources: McpCapabilityRegistry} $registries
     * @param array{tools: McpCapabilityDefinitionRegistry, prompts: McpCapabilityDefinitionRegistry, resources: McpCapabilityDefinitionRegistry} $definitions
     * @return array{enabled: list<string>, disabled: list<string>}
     */
    public static function syncMcpSessionCapabilities(array $registries, array $definitions, \Strapi\Permissions\Engine\Abilities\Ability $ability, bool $isDevMode): array
    {
        $summary = ['enabled' => [], 'disabled' => []];

        foreach (['tools', 'prompts', 'resources'] as $kind) {
            $definitions[$kind]->forEach(static function (array $definition) use ($registries, $kind, $ability, $isDevMode, &$summary): void {
                $name = (string) $definition['name'];
                $allowed = self::canUseMcpCapability($ability, $definition, $isDevMode);
                $status = $registries[$kind]->status($name);

                if ($status === 'disabled' && $allowed === true) {
                    $registries[$kind]->enable($name);
                    $summary['enabled'][] = $name;
                }

                if ($status === 'enabled' && $allowed === false) {
                    $registries[$kind]->disable($name);
                    $summary['disabled'][] = $name;
                }
            });
        }

        return $summary;
    }
}
