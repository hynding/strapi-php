<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Services\Utils;

/** Port of server/src/services/utils/get-plugins-that-need-documentation.ts. */
final class GetPluginsThatNeedDocumentation
{
    /**
     * @param array<string, mixed>|null $config the plugin config
     *
     * @return list<string>
     */
    public static function getPluginsThatNeedDocumentation(?array $config): array
    {
        // Default plugins that need documentation generated
        $defaultPlugins = ['upload', 'users-permissions'];

        // User specified plugins that need documentation generated
        $userPluginsConfig = $config['x-strapi-config']['plugins'] ?? null;

        if ($userPluginsConfig === null) {
            // The user hasn't specified any plugins to document, use the defaults
            return $defaultPlugins;
        }

        if (is_array($userPluginsConfig) && count($userPluginsConfig) > 0) {
            // The user has specified certain plugins to document, use them
            return array_values(array_map('strval', $userPluginsConfig));
        }

        // The user has specified that no plugins should be documented
        return [];
    }
}
