<?php

declare(strict_types=1);

namespace Strapi\Core\Registries;

use Strapi\Utils\Policy\PolicyDefinition;

/**
 * Port of packages/core/core/src/registries/policies.ts.
 *
 * A policy is a `callable(PolicyContext $ctx, array $config, Strapi $strapi): bool|null` or a
 * {@see PolicyDefinition} (`Policy::createPolicy(['validator' => ..., 'handler' => ...])`).
 *
 * @phpstan-type PolicyConfig string|array{name: string, config?: mixed}
 * @phpstan-type NamespaceInfo array{pluginName?: string|null, apiName?: string|null}
 */
final class Policies
{
    private const PLUGIN_PREFIX = 'plugin::';
    private const API_PREFIX = 'api::';

    /** @var array<string, callable|PolicyDefinition> */
    private array $policies = [];

    /**
     * @param PolicyConfig $policy
     * @return array{policyName: string, config: mixed}
     */
    private static function parsePolicy(string|array $policy): array
    {
        if (is_string($policy)) {
            return ['policyName' => $policy, 'config' => []];
        }

        return ['policyName' => (string) $policy['name'], 'config' => $policy['config'] ?? []];
    }

    /** @param NamespaceInfo|null $namespaceInfo */
    private function find(string $name, ?array $namespaceInfo = null): callable|PolicyDefinition|null
    {
        $pluginName = $namespaceInfo['pluginName'] ?? null;
        $apiName = $namespaceInfo['apiName'] ?? null;

        // try to resolve a full name to avoid extra prefixing
        if (isset($this->policies[$name])) {
            return $this->policies[$name];
        }

        if ($pluginName) {
            return $this->policies[self::PLUGIN_PREFIX . "{$pluginName}.{$name}"] ?? null;
        }

        if ($apiName) {
            return $this->policies[self::API_PREFIX . "{$apiName}.{$name}"] ?? null;
        }

        return null;
    }

    /**
     * @param PolicyConfig $policyConfig
     * @param NamespaceInfo|null $namespaceInfo
     */
    private function resolveHandler(string|array $policyConfig, ?array $namespaceInfo = null): callable
    {
        ['policyName' => $policyName, 'config' => $config] = self::parsePolicy($policyConfig);

        $policy = $this->find($policyName, $namespaceInfo);

        if ($policy === null) {
            throw new \RuntimeException("Policy {$policyName} not found.");
        }

        if ($policy instanceof PolicyDefinition) {
            $policy->validate($config);

            return $policy->handler;
        }

        return $policy;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->policies);
    }

    /** @param NamespaceInfo|null $namespaceInfo */
    public function get(string $name, ?array $namespaceInfo = null): callable|PolicyDefinition|null
    {
        return $this->find($name, $namespaceInfo);
    }

    /** @param NamespaceInfo|null $namespaceInfo */
    public function has(string $name, ?array $namespaceInfo = null): bool
    {
        return $this->find($name, $namespaceInfo) !== null;
    }

    /** @return array<string, callable|PolicyDefinition> */
    public function getAll(string $namespace = ''): array
    {
        return array_filter($this->policies, static fn (string $uid): bool => Namespace_::hasNamespace($uid, $namespace), ARRAY_FILTER_USE_KEY);
    }

    public function set(string $uid, callable|PolicyDefinition $policy): static
    {
        $this->policies[$uid] = $policy;

        return $this;
    }

    /** @param array<string, callable|PolicyDefinition> $newPolicies */
    public function add(string $namespace, array $newPolicies): void
    {
        foreach ($newPolicies as $policyName => $policy) {
            $uid = Namespace_::addNamespace((string) $policyName, $namespace);

            if (array_key_exists($uid, $this->policies)) {
                throw new \RuntimeException("Policy {$uid} has already been registered.");
            }

            $this->policies[$uid] = $policy;
        }
    }

    /**
     * Resolves a list of policies.
     *
     * @param PolicyConfig|callable|list<PolicyConfig|callable> $config
     * @param NamespaceInfo|null $namespaceInfo
     * @return list<array{handler: callable, config: mixed}>
     */
    public function resolve(mixed $config, ?array $namespaceInfo = null): array
    {
        $list = is_array($config) && array_is_list($config) ? $config : [$config];
        $info = ['pluginName' => $namespaceInfo['pluginName'] ?? null, 'apiName' => $namespaceInfo['apiName'] ?? null];

        $out = [];
        foreach ($list as $policyConfig) {
            // inline policy function (upstream accepts handlers directly in route config)
            if (!is_string($policyConfig) && is_callable($policyConfig)) {
                $out[] = ['handler' => $policyConfig, 'config' => []];
                continue;
            }
            if (is_array($policyConfig) && is_string($policyConfig['name'] ?? null)) {
                $policyConfig = ['name' => $policyConfig['name'], 'config' => $policyConfig['config'] ?? []];
            } elseif (!is_string($policyConfig)) {
                throw new \RuntimeException('Invalid policy configuration: expected a string, a function or {name, config}');
            }

            $out[] = [
                'handler' => $this->resolveHandler($policyConfig, $info),
                'config' => is_array($policyConfig) ? $policyConfig['config'] : [],
            ];
        }

        return $out;
    }
}
