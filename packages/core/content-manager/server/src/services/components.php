<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services;

use Strapi\ContentManager\Services\Utils\Store;
use Strapi\ContentManager\Utils\Utils;
use Strapi\Core\Strapi;

/** Port of server/src/services/components.ts. */
final class Components
{
    private const string STORE_KEY_PREFIX = 'components';

    private readonly Configuration $configurationService;

    public function __construct(private readonly Strapi $strapi)
    {
        $this->configurationService = new Configuration(
            $strapi,
            self::STORE_KEY_PREFIX,
            static function () use ($strapi): array {
                $dataMapper = Utils::getService($strapi, 'data-mapper');

                return array_map(static fn ($component): array => $dataMapper->toContentManagerModel($component), $strapi->components());
            },
            true,
        );
    }

    /** @return list<array<string, mixed>> */
    public function findAllComponents(): array
    {
        $dataMapper = Utils::getService($this->strapi, 'data-mapper');

        return array_values(array_map(static fn ($component): array => $dataMapper->toContentManagerModel($component), $this->strapi->components()));
    }

    /** @return array<string, mixed>|null */
    public function findComponent(string $uid): ?array
    {
        $dataMapper = Utils::getService($this->strapi, 'data-mapper');

        $component = $this->strapi->components()[$uid] ?? null;

        return $component === null ? null : $dataMapper->toContentManagerModel($component);
    }

    /**
     * @param array<string, mixed> $component
     * @return array<string, mixed>
     */
    public function findConfiguration(array $component): array
    {
        $configuration = $this->configurationService->getConfiguration((string) $component['uid']);

        return [
            'uid' => $component['uid'],
            'category' => $component['category'] ?? null,
            ...$configuration,
        ];
    }

    /**
     * @param array<string, mixed> $component
     * @param array<string, mixed> $newConfiguration
     * @return array<string, mixed>
     */
    public function updateConfiguration(array $component, array $newConfiguration): array
    {
        $this->configurationService->setConfiguration((string) $component['uid'], $newConfiguration);

        return $this->findConfiguration($component);
    }

    /**
     * Batch load component configurations.
     *
     * Collects all component UIDs upfront, then loads configurations in a single
     * batch query instead of sequential queries per component.
     *
     * @param array<string, mixed> $model
     * @return array<string, array<string, mixed>>
     */
    public function findComponentsConfigurations(array $model): array
    {
        // Cache on request state so the same request can reuse configs
        $requestState = $this->strapi->requestContext()->get()?->state();
        $requestCache = $requestState?->get('__componentsConfigurationsCache');

        /** @var array<string, true> $componentUids */
        $componentUids = [];

        $collectComponentUids = function (array $schema) use (&$collectComponentUids, &$componentUids): void {
            foreach (is_array($schema['attributes'] ?? null) ? $schema['attributes'] : [] as $attribute) {
                if (($attribute['type'] ?? null) === 'component') {
                    $uid = (string) $attribute['component'];
                    if (!isset($componentUids[$uid])) {
                        $componentUids[$uid] = true;
                        $nestedComponent = $this->findComponent($uid);
                        if ($nestedComponent !== null) {
                            $collectComponentUids($nestedComponent);
                        }
                    }
                }

                if (($attribute['type'] ?? null) === 'dynamiczone') {
                    foreach (is_array($attribute['components'] ?? null) ? $attribute['components'] : [] as $uid) {
                        $uid = (string) $uid;
                        if (!isset($componentUids[$uid])) {
                            $componentUids[$uid] = true;
                            $nestedComponent = $this->findComponent($uid);
                            if ($nestedComponent !== null) {
                                $collectComponentUids($nestedComponent);
                            }
                        }
                    }
                }
            }
        };

        $collectComponentUids($model);

        if ($componentUids === []) {
            return [];
        }

        // Key format must match configuration.ts uidToStoreKey: `${prefix}::${uid}`
        $uidsArray = array_map('strval', array_keys($componentUids));
        $prefixedKeys = array_map(static fn (string $uid): string => "components::{$uid}", $uidsArray);
        $sortedKeys = $prefixedKeys;
        sort($sortedKeys);
        $cacheKey = implode('|', $sortedKeys);
        if (is_array($requestCache) && isset($requestCache[$cacheKey])) {
            return $requestCache[$cacheKey];
        }

        $configs = Store::getModelConfigurations($this->strapi, $prefixedKeys);

        $componentsMap = [];

        foreach ($uidsArray as $uid) {
            $component = $this->findComponent($uid);
            $configKey = "components::{$uid}";
            // Fallback must include proper layouts structure for frontend compatibility
            $configuration = $configs[$configKey] ?? Store::EMPTY_CONFIG;

            $componentsMap[$uid] = [
                'uid' => $component['uid'] ?? null,
                'category' => $component['category'] ?? null,
                ...$configuration,
            ];
        }

        if ($requestState !== null) {
            $cache = is_array($requestCache) ? $requestCache : [];
            $cache[$cacheKey] = $componentsMap;
            $requestState->set('__componentsConfigurationsCache', $cache);
        }

        return $componentsMap;
    }

    public function syncConfigurations(): void
    {
        $this->configurationService->syncConfigurations();
    }
}
