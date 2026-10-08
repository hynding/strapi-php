<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Utils;

use Strapi\Core\Services\ScopedCoreStore;
use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Services;

/**
 * Port of server/src/utils/index.ts. `getService(name)` reads the global `strapi` upstream; here
 * the instance is passed. The typed helpers resolve the plugin's own services.
 */
final class Utils
{
    public static function getCoreStore(Strapi $strapi): ScopedCoreStore
    {
        return $strapi->store()->scoped(['type' => 'plugin', 'name' => 'i18n']);
    }

    /** retrieve a local service */
    public static function getService(Strapi $strapi, string $name): object
    {
        return $strapi->service("plugin::i18n.{$name}");
    }

    public static function locales(Strapi $strapi): Services\Locales
    {
        $service = self::getService($strapi, 'locales');
        assert($service instanceof Services\Locales);

        return $service;
    }

    public static function contentTypes(Strapi $strapi): Services\ContentTypes
    {
        $service = self::getService($strapi, 'content-types');
        assert($service instanceof Services\ContentTypes);

        return $service;
    }

    public static function permissions(Strapi $strapi): Services\Permissions
    {
        $service = self::getService($strapi, 'permissions');
        assert($service instanceof Services\Permissions);

        return $service;
    }

    public static function metrics(Strapi $strapi): Services\Metrics
    {
        $service = self::getService($strapi, 'metrics');
        assert($service instanceof Services\Metrics);

        return $service;
    }

    public static function localizations(Strapi $strapi): Services\Localizations
    {
        $service = self::getService($strapi, 'localizations');
        assert($service instanceof Services\Localizations);

        return $service;
    }

    public static function isoLocales(Strapi $strapi): Services\IsoLocales
    {
        $service = self::getService($strapi, 'iso-locales');
        assert($service instanceof Services\IsoLocales);

        return $service;
    }

    public static function settings(Strapi $strapi): Services\Settings
    {
        $service = self::getService($strapi, 'settings');
        assert($service instanceof Services\Settings);

        return $service;
    }

    public static function aiTranslations(Strapi $strapi): Services\AiTranslations
    {
        $service = self::getService($strapi, 'ai-translations');
        assert($service instanceof Services\AiTranslations);

        return $service;
    }

    public static function aiLocalizations(Strapi $strapi): Services\AiLocalizations
    {
        $service = self::getService($strapi, 'ai-localizations');
        assert($service instanceof Services\AiLocalizations);

        return $service;
    }

    public static function aiLocalizationJobs(Strapi $strapi): Services\AiLocalizationJobs
    {
        $service = self::getService($strapi, 'ai-localization-jobs');
        assert($service instanceof Services\AiLocalizationJobs);

        return $service;
    }

    public static function fillFromLocale(Strapi $strapi): Services\FillFromLocale
    {
        $service = self::getService($strapi, 'fill-from-locale');
        assert($service instanceof Services\FillFromLocale);

        return $service;
    }
}
