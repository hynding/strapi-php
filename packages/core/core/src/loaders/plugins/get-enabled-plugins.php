<?php

declare(strict_types=1);

namespace Strapi\Core\Loaders\Plugins;

use Strapi\Core\Strapi;
use Strapi\Utils\Primitives\Objects;
use Strapi\Utils\Primitives\Strings;

/**
 * Port of packages/core/core/src/loaders/plugins/get-enabled-plugins.ts.
 *
 * npm packages become Composer packages: an installed plugin is a package in `vendor/composer/installed.json`
 * whose `composer.json` has `extra.strapi.kind = "plugin"`; `extra.strapi.name` is the plugin name and
 * `extra.strapi.server` (default `strapi-server.php`) its server entry file. Internal plugins are the
 * `strapi/plugin-*` packages listed in INTERNAL_PLUGINS when they are installed.
 *
 * @phpstan-type PluginMeta array{enabled: bool, pathToPlugin?: string, info: array<string, mixed>, packageInfo?: array<string, mixed>}
 */
final class GetEnabledPlugins
{
    /**
     * otherwise known as "core features"
     * NOTE: These are excluded from the content manager plugin list, as they are always enabled.
     */
    public const INTERNAL_PLUGINS = [
        'strapi/content-manager',
        'strapi/content-type-builder',
        'strapi/email',
        'strapi/upload',
        'strapi/i18n',
        'strapi/content-releases',
        'strapi/review-workflows',
    ];

    /** @param array<string, mixed> $info composer.json contents */
    private static function isStrapiPlugin(array $info): bool
    {
        return ($info['extra']['strapi']['kind'] ?? null) === 'plugin';
    }

    public static function validatePluginName(string $pluginName): void
    {
        if (!Strings::isKebabCase($pluginName)) {
            throw new \RuntimeException("Plugin name \"{$pluginName}\" is not in kebab (an-example-of-kebab-case)");
        }
    }

    /**
     * @param bool|array<string, mixed> $declaration
     * @return array{enabled: bool, pathToPlugin?: string}
     */
    private static function toDetailedDeclaration(Strapi $strapi, bool|array $declaration): array
    {
        if (is_bool($declaration)) {
            return ['enabled' => $declaration];
        }

        $detailed = ['enabled' => (bool) ($declaration['enabled'] ?? false)];

        if (!empty($declaration['resolve'])) {
            $resolve = (string) $declaration['resolve'];
            $pathToPlugin = str_starts_with($resolve, '/') ? $resolve : $strapi->dirs()->root . '/' . $resolve;
            $real = realpath($pathToPlugin);

            if ($real === false || !is_dir($real)) {
                // maybe a Composer package name
                $installed = self::installedPackage($strapi, $resolve);
                if ($installed === null) {
                    throw new \RuntimeException("{$resolve} couldn't be resolved");
                }
                $real = $installed['path'];
            }

            $detailed['pathToPlugin'] = $real;
        }

        return $detailed;
    }

    /**
     * Every Composer package installed in the project (`vendor/composer/installed.json`).
     *
     * @return array<string, array{path: string, info: array<string, mixed>}> keyed by package name
     */
    public static function installedPackages(Strapi $strapi): array
    {
        static $cache = [];
        $root = $strapi->dirs()->root;
        if (isset($cache[$root])) {
            return $cache[$root];
        }

        $packages = [];
        foreach ([$root . '/vendor', dirname(__DIR__, 5) . '/vendor'] as $vendorDir) {
            $file = $vendorDir . '/composer/installed.json';
            if (!is_file($file)) {
                continue;
            }
            $decoded = json_decode((string) file_get_contents($file), true);
            $list = is_array($decoded) ? ($decoded['packages'] ?? $decoded) : [];
            foreach ($list as $package) {
                if (!is_array($package) || !isset($package['name'])) {
                    continue;
                }
                $installPath = $package['install-path'] ?? null;
                $path = is_string($installPath) ? realpath($vendorDir . '/composer/' . $installPath) : false;
                if ($path === false) {
                    continue;
                }
                $packages[$package['name']] ??= ['path' => $path, 'info' => $package];
            }
        }

        return $cache[$root] = $packages;
    }

    /** @return array{path: string, info: array<string, mixed>}|null */
    private static function installedPackage(Strapi $strapi, string $name): ?array
    {
        return self::installedPackages($strapi)[$name] ?? null;
    }

    /**
     * @return array<string, PluginMeta>
     */
    public static function getEnabledPlugins(Strapi $strapi): array
    {
        $installed = self::installedPackages($strapi);

        $internalPlugins = [];
        foreach (self::INTERNAL_PLUGINS as $dep) {
            if (!isset($installed[$dep])) {
                continue;
            }
            $packageInfo = $installed[$dep]['info'];
            $pluginName = (string) ($packageInfo['extra']['strapi']['name'] ?? substr($dep, strlen('strapi/')));
            self::validatePluginName($pluginName);
            $internalPlugins[$pluginName] = [
                'enabled' => true,
                'pathToPlugin' => $installed[$dep]['path'],
                'info' => $packageInfo['extra']['strapi'] ?? [],
                'packageInfo' => $packageInfo,
            ];
        }

        $installedPlugins = [];
        foreach ($installed as $dep => ['path' => $path, 'info' => $packageInfo]) {
            if (!self::isStrapiPlugin($packageInfo) || in_array($dep, self::INTERNAL_PLUGINS, true)) {
                continue;
            }
            $pluginName = (string) ($packageInfo['extra']['strapi']['name'] ?? basename($dep));
            self::validatePluginName($pluginName);
            $installedPlugins[$pluginName] = [
                'enabled' => true,
                'pathToPlugin' => $path,
                'info' => [...$packageInfo['extra']['strapi'], 'packageName' => $dep],
                'packageInfo' => $packageInfo,
            ];
        }

        $declaredPlugins = [];
        $userPluginsConfig = GetUserPluginsConfig::getUserPluginsConfig($strapi);

        foreach ($userPluginsConfig as $pluginName => $declaration) {
            $pluginName = (string) $pluginName;
            self::validatePluginName($pluginName);

            if (!is_bool($declaration) && !is_array($declaration)) {
                continue;
            }

            $declaredPlugins[$pluginName] = [...self::toDetailedDeclaration($strapi, $declaration), 'info' => []];

            $pathToPlugin = $declaredPlugins[$pluginName]['pathToPlugin'] ?? null;

            // for manually resolved plugins
            if ($pathToPlugin !== null) {
                $packagePath = $pathToPlugin . '/composer.json';
                $packageInfo = is_file($packagePath) ? json_decode((string) file_get_contents($packagePath), true) : [];
                $packageInfo = is_array($packageInfo) ? $packageInfo : [];

                if (self::isStrapiPlugin($packageInfo)) {
                    $declaredPlugins[$pluginName]['info'] = $packageInfo['extra']['strapi'] ?? [];
                    $declaredPlugins[$pluginName]['packageInfo'] = $packageInfo;
                }
            }
        }

        $declaredPluginsResolves = array_values(array_filter(array_map(static fn (array $p): ?string => $p['pathToPlugin'] ?? null, $declaredPlugins)));
        $installedPluginsNotAlreadyUsed = array_filter(
            $installedPlugins,
            static fn (array $p): bool => !in_array($p['pathToPlugin'] ?? null, $declaredPluginsResolves, true),
        );

        // defaultsDeep({}, internal, declared, installed): first definition wins per key
        $allPlugins = self::defaultsDeep($internalPlugins, $declaredPlugins, $installedPluginsNotAlreadyUsed);

        return array_filter($allPlugins, static fn (array $plugin): bool => (bool) $plugin['enabled']);
    }

    /**
     * lodash defaultsDeep: fill missing keys from each next source, recursively.
     *
     * @param array<string, mixed> ...$sources
     * @return array<string, mixed>
     */
    private static function defaultsDeep(array ...$sources): array
    {
        $result = [];
        foreach ($sources as $source) {
            foreach ($source as $key => $value) {
                if (!array_key_exists($key, $result) || $result[$key] === null) {
                    $result[$key] = $value;
                } elseif (is_array($result[$key]) && is_array($value) && !array_is_list($value)) {
                    $result[$key] = self::defaultsDeep($result[$key], $value);
                }
            }
        }

        return $result;
    }
}
