<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services\Utils;

use Strapi\Core\Strapi;
use Strapi\Utils\Primitives\Objects;

/**
 * Port of server/src/services/utils/store.ts (model configurations in the core store).
 *
 * Upstream reads the global `strapi`; here every function takes it first. PHP arrays have no
 * empty object, so {@see toJsonConfiguration()} turns the `{}` parts of a configuration back into
 * objects before it is stored or sent (core-store values keep upstream's JSON shape).
 */
final class Store
{
    public const array KEYS = ['CONFIGURATION' => 'configuration'];

    private const string STORE_KEY_PREFIX = 'plugin_content_manager_';

    /** Model configuration */
    public const array EMPTY_CONFIG = [
        'settings' => [],
        'metadatas' => [],
        'layouts' => ['list' => [], 'edit' => []],
    ];

    private static function getStore(Strapi $strapi): \Strapi\Core\Services\ScopedCoreStore
    {
        return $strapi->store()->scoped(['type' => 'plugin', 'name' => 'content_manager']);
    }

    private static function configurationKey(string $key): string
    {
        return self::KEYS['CONFIGURATION'] . "_{$key}";
    }

    /** @return array<mixed> */
    public static function getModelConfiguration(Strapi $strapi, string $key): array
    {
        $config = self::getStore($strapi)->get(['key' => self::configurationKey($key)]);

        return Objects::merge(self::EMPTY_CONFIG, is_array($config) ? $config : []);
    }

    /**
     * Batch load multiple model configurations in a single query.
     *
     * @param list<string> $keys Array of configuration keys (e.g., ['components::sections.hero', ...])
     * @return array<string, array<mixed>> Map of key -> configuration object
     */
    public static function getModelConfigurations(Strapi $strapi, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $configKeys = array_map(static fn (string $k): string => self::STORE_KEY_PREFIX . self::configurationKey($k), $keys);
        $results = $strapi->db()->query('strapi::core-store')->findMany([
            'where' => ['key' => ['$in' => $configKeys]],
        ]);

        $configMap = [];
        foreach ($results as $result) {
            $originalKey = str_replace(self::STORE_KEY_PREFIX . 'configuration_', '', (string) $result['key']);
            $value = $result['value'] ?? null;
            if (is_string($value)) {
                try {
                    $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $strapi->log()->warning("Malformed JSON in core-store key \"{$result['key']}\", using default config");
                    continue;
                }
            }
            $configMap[$originalKey] = Objects::merge(self::EMPTY_CONFIG, is_array($value) ? $value : []);
        }

        // Default missing keys to empty config
        foreach ($keys as $key) {
            if (!isset($configMap[$key])) {
                $configMap[$key] = self::EMPTY_CONFIG;
            }
        }

        return $configMap;
    }

    /** @param array<string, mixed> $value */
    public static function setModelConfiguration(Strapi $strapi, string $key, array $value): void
    {
        $storedConfig = self::getStore($strapi)->get(['key' => self::configurationKey($key)]);
        $storedConfig = is_array($storedConfig) ? $storedConfig : [];
        $currentConfig = $storedConfig;

        foreach ($value as $k => $v) {
            if ($v !== null) {
                $currentConfig = Objects::set($currentConfig, (string) $k, $v);
            }
        }

        if ($currentConfig != $storedConfig) {
            self::getStore($strapi)->set([
                'key' => self::configurationKey($key),
                'value' => self::toJsonConfiguration($currentConfig),
            ]);
        }
    }

    public static function deleteKey(Strapi $strapi, string $key): void
    {
        $strapi->db()->query('strapi::core-store')->delete([
            'where' => ['key' => self::STORE_KEY_PREFIX . "configuration_{$key}"],
        ]);
    }

    /** @return list<array<string, mixed>> */
    public static function findByKey(Strapi $strapi, string $key): array
    {
        $results = $strapi->db()->query('strapi::core-store')->findMany([
            'where' => ['key' => ['$startsWith' => $key]],
        ]);

        $out = [];
        foreach ($results as $result) {
            try {
                $decoded = json_decode((string) ($result['value'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $strapi->log()->warning("Malformed JSON in core-store key \"{$result['key']}\", skipping entry");
                continue;
            }
            if ($decoded !== null) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function getAllConfigurations(Strapi $strapi): array
    {
        return self::findByKey($strapi, self::STORE_KEY_PREFIX . 'configuration');
    }

    /**
     * JSON shape of a configuration: `settings`, `metadatas`, each metadata and its `edit` / `list`,
     * `layouts` and `options` are objects even when empty.
     *
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    public static function toJsonConfiguration(array $configuration): array
    {
        foreach (['settings', 'options'] as $key) {
            if (array_key_exists($key, $configuration) && $configuration[$key] === []) {
                $configuration[$key] = new \stdClass();
            }
        }

        if (isset($configuration['metadatas']) && is_array($configuration['metadatas'])) {
            $metadatas = [];
            foreach ($configuration['metadatas'] as $name => $metadata) {
                if (is_array($metadata)) {
                    foreach (['edit', 'list'] as $part) {
                        if (array_key_exists($part, $metadata) && $metadata[$part] === []) {
                            $metadata[$part] = new \stdClass();
                        }
                    }
                    $metadatas[$name] = $metadata === [] ? new \stdClass() : $metadata;
                } else {
                    $metadatas[$name] = $metadata;
                }
            }
            $configuration['metadatas'] = $metadatas === [] ? new \stdClass() : $metadatas;
        }

        if (isset($configuration['layouts']) && $configuration['layouts'] === []) {
            $configuration['layouts'] = new \stdClass();
        }

        return $configuration;
    }
}
