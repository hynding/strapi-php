<?php

declare(strict_types=1);

namespace Strapi\Core\Loaders\Plugins;

use Strapi\Core\Domain\ContentType\ContentType;
use Strapi\Core\Strapi;
use Strapi\Core\Utils\LoadConfigFile;
use Strapi\Core\Utils\LoadFiles;
use Strapi\Utils\Primitives\Objects;

/**
 * Port of packages/core/core/src/loaders/plugins/index.ts (`loadPlugins`).
 *
 * A plugin's server entry (`strapi-server.php`, or `extra.strapi.server` in its composer.json)
 * returns the raw module array (`register`, `bootstrap`, `destroy`, `config` (`['default' => ..., 'validator' => fn]`),
 * `routes`, `controllers`, `services`, `policies`, `middlewares`, `contentTypes`), or a
 * `callable(): array`. User extensions live in `src/extensions/<plugin>/content-types/<ct>/schema.json`
 * and `src/extensions/<plugin>/strapi-server.php` (`callable(array $plugin): array`).
 */
final class Plugins
{
    public function __invoke(Strapi $strapi): void
    {
        self::loadPlugins($strapi);
    }

    /** @return array<string, mixed> */
    private static function defaultPlugin(): array
    {
        return [
            'bootstrap' => static function (): void {},
            'destroy' => static function (): void {},
            'register' => static function (): void {},
            'config' => ['default' => [], 'validator' => static function (): void {}],
            'routes' => [],
            'controllers' => [],
            'services' => [],
            'policies' => [],
            'middlewares' => [],
            'contentTypes' => [],
        ];
    }

    public static function loadPlugins(Strapi $strapi): void
    {
        $plugins = [];

        $enabledPlugins = GetEnabledPlugins::getEnabledPlugins($strapi);

        $strapi->config()->set('enabledPlugins', $enabledPlugins);

        foreach ($enabledPlugins as $pluginName => $enabledPlugin) {
            $pluginName = (string) $pluginName;
            $pathToPlugin = $enabledPlugin['pathToPlugin'] ?? null;
            if ($pathToPlugin === null) {
                throw new \RuntimeException("Error loading the plugin {$pluginName} because {$pluginName} is not installed. Please either install the plugin or remove its configuration.");
            }

            $resolvedExport = (string) ($enabledPlugin['packageInfo']['extra']['strapi']['server'] ?? 'strapi-server.php');
            $serverEntrypointPath = $pathToPlugin . '/' . ltrim($resolvedExport, './');

            // only load plugins with a server entrypoint
            if (!is_file($serverEntrypointPath)) {
                continue;
            }

            $pluginServer = LoadConfigFile::loadConfigFile($serverEntrypointPath, $strapi->env());
            if (!is_array($pluginServer)) {
                throw new \RuntimeException("Invalid server entry for plugin {$pluginName}: {$serverEntrypointPath} must return an array");
            }

            $default = self::defaultPlugin();
            $plugins[$pluginName] = [
                ...$default,
                ...$pluginServer,
                'contentTypes' => self::formatContentTypes($pluginName, $pluginServer['contentTypes'] ?? []),
                'config' => [...$default['config'], ...($pluginServer['config'] ?? [])],
                'routes' => $pluginServer['routes'] ?? $default['routes'],
            ];
        }

        self::applyUserConfig($strapi, $plugins);
        self::applyUserExtension($strapi, $plugins);

        foreach ($plugins as $pluginName => $plugin) {
            $strapi->get('plugins')->add((string) $pluginName, $plugin);
        }
    }

    /** @param array<string, array<string, mixed>> $plugins */
    private static function applyUserConfig(Strapi $strapi, array &$plugins): void
    {
        $userPluginsConfig = GetUserPluginsConfig::getUserPluginsConfig($strapi);

        foreach ($plugins as $pluginName => $plugin) {
            $declaration = $userPluginsConfig[$pluginName] ?? null;
            $userPluginConfig = is_array($declaration) && is_array($declaration['config'] ?? null) ? $declaration['config'] : [];

            $defaultConfig = $plugin['config']['default'] ?? [];
            if (is_callable($defaultConfig)) {
                $defaultConfig = $defaultConfig($strapi->env());
            }

            $config = Objects::merge([], is_array($defaultConfig) ? $defaultConfig : [], $userPluginConfig);

            try {
                $validator = $plugin['config']['validator'] ?? null;
                if (is_callable($validator)) {
                    $validator($config);
                }
            } catch (\Throwable $e) {
                throw new \RuntimeException("Error regarding {$pluginName} config: {$e->getMessage()}", 0, $e);
            }

            $plugins[$pluginName]['config'] = $config;
        }
    }

    /** @param array<string, array<string, mixed>> $plugins */
    private static function applyUserExtension(Strapi $strapi, array &$plugins): void
    {
        $extensionsDir = $strapi->dirs()->extensions;
        if (!is_dir($extensionsDir)) {
            return;
        }

        $extendedSchemas = LoadFiles::loadFiles($extensionsDir, '**/content-types/**/schema.json');
        $strapiServers = LoadFiles::loadFiles($extensionsDir, '**/strapi-server.php');

        foreach ($plugins as $pluginName => $plugin) {
            // first: load json schema
            $extendedContentTypes = $extendedSchemas[$pluginName]['content-types'] ?? [];
            if (is_array($extendedContentTypes)) {
                foreach ($extendedContentTypes as $ctName => $extension) {
                    $extendedSchema = is_array($extension) ? ($extension['schema'] ?? null) : null;
                    if (!is_array($extendedSchema)) {
                        continue;
                    }
                    unset($extendedSchema['__filename__']);

                    if (!isset($plugin['contentTypes'][$ctName])) {
                        $plugin['contentTypes'][$ctName] = ['schema' => $extendedSchema];
                    } else {
                        $plugin['contentTypes'][$ctName]['schema'] = [...$plugin['contentTypes'][$ctName]['schema'], ...$extendedSchema];
                    }
                }
            }

            $plugin['contentTypes'] = self::formatContentTypes((string) $pluginName, $plugin['contentTypes']);

            // second: execute strapi-server extension
            $strapiServer = $strapiServers[$pluginName]['strapi-server'] ?? null;
            if (is_callable($strapiServer)) {
                $plugin = $strapiServer($plugin, $strapi);
            }

            $plugins[$pluginName] = $plugin;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $contentTypes
     * @return array<string, array<string, mixed>>
     */
    private static function formatContentTypes(string $pluginName, array $contentTypes): array
    {
        foreach ($contentTypes as $name => $definition) {
            $schema = $definition['schema'] ?? [];
            $schema['plugin'] = $pluginName;
            $schema['collectionName'] = $schema['collectionName'] ?? strtolower("{$pluginName}_" . ($schema['info']['singularName'] ?? $name));
            $schema['globalId'] = ContentType::getGlobalId($schema, $pluginName);
            $contentTypes[$name]['schema'] = $schema;
        }

        return $contentTypes;
    }
}
