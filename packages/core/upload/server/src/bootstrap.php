<?php

declare(strict_types=1);

namespace Strapi\Upload;

use Strapi\Core\Strapi;
use Strapi\Upload\Services\AiMetadataProvider;
use Strapi\Upload\Utils\Utils;

/** Port of server/src/bootstrap.ts. */
final class Bootstrap
{
    public function __invoke(Strapi $strapi): void
    {
        $defaultConfig = [
            'settings' => [
                'sizeOptimization' => true,
                'responsiveDimensions' => true,
                'autoOrientation' => false,
                'aiMetadata' => true,
            ],
            'view_configuration' => [
                'pageSize' => 10,
                'sort' => Constants::ALLOWED_SORT_STRINGS[0],
            ],
        ];

        // Whether this plugin has stored settings from an earlier boot, which is what separates
        // an upgrading app from a fresh install for the Media Library default notice below.
        $isExistingApp = false;

        foreach ($defaultConfig as $key => $defaultValue) {
            // set plugin store
            $configurator = ['type' => 'plugin', 'name' => 'upload', 'key' => $key];

            $config = $strapi->store()->get($configurator);

            if ($config) {
                $isExistingApp = true;
            }
            if (is_array($config) && array_diff_key($defaultValue, $config) === []) {
                continue;
            }

            // if the config does not exist or does not have all the required keys
            // set from the defaultValue ensuring all required settings are present
            $strapi->store()->set([...$configurator, 'value' => [...$defaultValue, ...(is_array($config) ? $config : [])]]);
        }

        MediaLibraryDefaultNotice::notifyMediaLibraryDefault($strapi, $isExistingApp);

        self::registerPermissionActions($strapi);
        self::registerWebhookEvents($strapi);

        Utils::getService('weeklyMetrics', $strapi)->registerCron();

        // AI metadata
        if (AiMetadataProvider::aiAdminCall($strapi, 'isAvailable')) {
            $aiMetadataProvider = Utils::getService('aiMetadataProvider', $strapi);

            if (!$aiMetadataProvider->hasProvider() && AiMetadataProvider::aiAdminCall($strapi, 'isStrapiManagedAiEnabled')) {
                $aiMetadataProvider->registerStrapiManagedProvider();
            }
        }

        Utils::getService('metrics', $strapi)->sendUploadPluginMetrics();

        Utils::getService('extensions', $strapi)->signFileUrlsOnDocumentService();
    }

    private static function registerWebhookEvents(Strapi $strapi): void
    {
        foreach (Constants::ALLOWED_WEBHOOK_EVENTS as $key => $value) {
            $strapi->get('webhookStore')->addAllowedEvent($key, $value);
        }
    }

    private static function registerPermissionActions(Strapi $strapi): void
    {
        $actions = [
            [
                'section' => 'plugins',
                'displayName' => 'Access the Media Library',
                'uid' => 'read',
                'pluginName' => 'upload',
            ],
            [
                'section' => 'plugins',
                'displayName' => 'Create (upload)',
                'uid' => 'assets.create',
                'subCategory' => 'assets',
                'pluginName' => 'upload',
            ],
            [
                'section' => 'plugins',
                'displayName' => 'Update (crop, details, replace) + delete',
                'uid' => 'assets.update',
                'subCategory' => 'assets',
                'pluginName' => 'upload',
            ],
            [
                'section' => 'plugins',
                'displayName' => 'Download',
                'uid' => 'assets.download',
                'subCategory' => 'assets',
                'pluginName' => 'upload',
            ],
            [
                'section' => 'plugins',
                'displayName' => 'Copy link',
                'uid' => 'assets.copy-link',
                'subCategory' => 'assets',
                'pluginName' => 'upload',
            ],
            [
                'section' => 'plugins',
                'displayName' => 'Configure view',
                'uid' => 'configure-view',
                'pluginName' => 'upload',
            ],
            [
                'section' => 'settings',
                'displayName' => 'Access the Media Library settings page',
                'uid' => 'settings.read',
                'category' => 'media library',
                'pluginName' => 'upload',
            ],
        ];

        Utils::permissionService($strapi)->actionProvider->registerMany($actions);
    }
}
