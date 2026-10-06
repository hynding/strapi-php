<?php

declare(strict_types=1);

namespace Strapi\Cli\Node\Core;

use Strapi\Cli\Cli\Utils\Logger;
use Strapi\Core\Strapi;
use Strapi\Utils\Primitives\Strings;

/**
 * Port of packages/core/strapi/src/node/core/plugins.ts: which plugins have an admin part to bundle.
 *
 * Two sources, like upstream: the npm dependencies of the project's `package.json` whose own
 * `package.json` has `strapi.kind = "plugin"` (modules, imported by name), and the local plugins
 * enabled with `{ enabled: true, resolve: './src/plugins/x' }` in `config/plugins.php`.
 *
 * @phpstan-type PluginMeta array{name: string, importName: string, type: 'local'|'module', modulePath: string, path?: string}
 */
final class Plugins
{
    /**
     * @return array<string, PluginMeta>
     */
    public static function getEnabledPlugins(string $cwd, Logger $logger, string $runtimeDir, Strapi $strapi): array
    {
        $plugins = [];

        /**
         * The dependencies installed in the user's project (package.json). It will include
         * libraries like "react", so we need to collect the ones that are plugins.
         */
        $packageJson = self::readJson($cwd . '/package.json');
        $deps = is_array($packageJson['dependencies'] ?? null) ? $packageJson['dependencies'] : [];

        $logger->debug("Dependencies from user's project", $deps);

        $userPluginsFile = self::loadUserPluginsFile($strapi);

        $logger->debug("User's plugins file", $userPluginsFile);

        foreach (array_keys($deps) as $dep) {
            $pkg = self::getModule((string) $dep, $cwd);

            if ($pkg !== null && self::validatePackageIsPlugin($pkg)) {
                $name = $pkg['strapi']['name'] ?? $pkg['name'] ?? null;

                if (!is_string($name) || $name === '') {
                    throw new \RuntimeException("You're trying to import a plugin that doesn't have a name – check the package.json of that plugin!");
                }

                $userPluginConfig = $userPluginsFile[$name] ?? null;

                if ($userPluginConfig !== null && !self::isPluginConfigEnabled($userPluginConfig)) {
                    continue;
                }

                $plugins[$name] = [
                    'name' => $name,
                    'importName' => Strings::camelCase($name),
                    'type' => 'module',
                    'modulePath' => (string) $dep,
                ];
            }
        }

        foreach ($userPluginsFile as $userPluginName => $userPluginConfig) {
            /**
             * Local plugins must be explicitly enabled to be registered, matching the server-side
             * loader (get-enabled-plugins), which drops a `{ resolve }` declaration without a truthy `enabled`.
             */
            if (is_array($userPluginConfig) && ($userPluginConfig['enabled'] ?? false) && is_string($userPluginConfig['resolve'] ?? null)) {
                $resolve = Files::convertModulePathToSystemPath($userPluginConfig['resolve']);
                $sysPath = str_starts_with($resolve, '/') ? $resolve : $cwd . '/' . ltrim($resolve, './');
                $sysPath = realpath($sysPath) ?: $sysPath;

                $plugins[(string) $userPluginName] = [
                    'name' => (string) $userPluginName,
                    'importName' => Strings::camelCase((string) $userPluginName),
                    'type' => 'local',
                    // User plugin paths are resolved from the entry point of the app, because that's how you import them.
                    'modulePath' => Files::convertSystemPathToModulePath(Files::relative($runtimeDir, $sysPath)),
                    'path' => $sysPath,
                ];
            }
        }

        return $plugins;
    }

    /**
     * The `config/plugins.php` of the project, through the loaded configuration.
     *
     * @return array<string, mixed>
     */
    private static function loadUserPluginsFile(Strapi $strapi): array
    {
        $config = $strapi->config()->get('plugins', []);

        return is_array($config) ? $config : [];
    }

    private static function isPluginConfigEnabled(mixed $config): bool
    {
        if (is_bool($config)) {
            return $config;
        }

        return !is_array($config) || ($config['enabled'] ?? true) !== false;
    }

    /** @param array<string, mixed> $pkg */
    private static function validatePackageIsPlugin(array $pkg): bool
    {
        return is_array($pkg['strapi'] ?? null) && ($pkg['strapi']['kind'] ?? null) === 'plugin';
    }

    /**
     * The package.json of an installed npm module.
     *
     * @return array<string, mixed>|null
     */
    public static function getModule(string $name, string $cwd): ?array
    {
        $dir = $cwd;
        while (true) {
            $candidate = $dir . '/node_modules/' . $name . '/package.json';
            if (is_file($candidate)) {
                $json = self::readJson($candidate);

                return $json === [] ? null : $json;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                return null;
            }
            $dir = $parent;
        }
    }

    /**
     * Keeps the plugins with an admin part: a local plugin with `exports['./strapi-admin'].import`
     * or a `strapi-admin.js`, a module with a `strapi-admin` export.
     *
     * @param array<string, PluginMeta> $plugins
     * @return list<PluginMeta>
     */
    public static function getMapOfPluginsWithAdmin(array $plugins, string $cwd): array
    {
        $pluginImportPaths = [];
        $out = [];

        foreach ($plugins as $plugin) {
            $localPluginPath = $plugin['path'] ?? null;
            if ($localPluginPath !== null) {
                $packageJsonPath = $localPluginPath . '/package.json';
                if (is_file($packageJsonPath)) {
                    $packageJson = self::readJson($packageJsonPath);
                    $localAdminPath = $packageJson['exports']['./strapi-admin']['import'] ?? null;
                    if (is_string($localAdminPath)) {
                        $pluginImportPaths[$plugin['modulePath']] = $localAdminPath;
                        $out[] = [...$plugin, 'modulePath' => $plugin['modulePath'] . '/' . ltrim($localAdminPath, './')];
                        continue;
                    }
                }
                if (is_file($localPluginPath . '/strapi-admin.js')) {
                    $pluginImportPaths[$plugin['modulePath']] = 'strapi-admin';
                    $out[] = [...$plugin, 'modulePath' => $plugin['modulePath'] . '/strapi-admin'];
                    continue;
                }
                continue;
            }

            // This plugin is a module: does it have a strapi-admin export?
            $pkg = self::getModule($plugin['modulePath'], $cwd);
            if ($pkg === null) {
                continue;
            }
            $exports = $pkg['exports'] ?? null;
            $hasAdmin = (is_array($exports) && array_key_exists('./strapi-admin', $exports))
                || self::moduleFileExists($plugin['modulePath'] . '/strapi-admin.js', $cwd);
            if ($hasAdmin) {
                $pluginImportPaths[$plugin['modulePath']] = 'strapi-admin';
                $out[] = [...$plugin, 'modulePath' => $plugin['modulePath'] . '/strapi-admin'];
            }
        }

        return $out;
    }

    private static function moduleFileExists(string $relative, string $cwd): bool
    {
        $dir = $cwd;
        while (true) {
            if (is_file($dir . '/node_modules/' . $relative)) {
                return true;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                return false;
            }
            $dir = $parent;
        }
    }

    /** @return array<string, mixed> */
    public static function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($path), true);

        return is_array($json) ? $json : [];
    }
}
