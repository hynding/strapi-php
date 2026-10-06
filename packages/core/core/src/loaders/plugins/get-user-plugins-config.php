<?php

declare(strict_types=1);

namespace Strapi\Core\Loaders\Plugins;

use Strapi\Core\Strapi;
use Strapi\Core\Utils\LoadConfigFile;
use Strapi\Utils\Primitives\Objects;

/**
 * Port of packages/core/core/src/loaders/plugins/get-user-plugins-config.ts.
 *
 * Returns the user's plugin declarations: `config/plugins.php` merged with `config/env/<env>/plugins.php`.
 * A declaration is `true|false` or `['enabled' => bool, 'resolve' => string, 'config' => [...]]`.
 */
final class GetUserPluginsConfig
{
    /** @return array<string, bool|array<string, mixed>> */
    public static function getUserPluginsConfig(Strapi $strapi): array
    {
        $configDir = $strapi->dirs()->config;
        $env = $strapi->env();
        $environment = (string) $strapi->config()->get('environment', 'development');

        $globalUserConfigPath = $configDir . '/plugins.php';
        $currentEnvUserConfigPath = $configDir . '/env/' . $environment . '/plugins.php';
        $config = [];

        // assign global user config if exists
        if (is_file($globalUserConfigPath)) {
            $loaded = LoadConfigFile::loadConfigFile($globalUserConfigPath, $env);
            $config = is_array($loaded) ? $loaded : [];
        }

        // and merge user config by environment if exists
        if (is_file($currentEnvUserConfigPath)) {
            $loaded = LoadConfigFile::loadConfigFile($currentEnvUserConfigPath, $env);
            $config = Objects::merge([], $config, is_array($loaded) ? $loaded : []);
        }

        return $config;
    }
}
