<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Constants;

/**
 * Port of server/src/constants/index.ts.
 *
 * `isoLocales` is read from the same `iso-locales.json`. `DEFAULT_LOCALE` is computed when first
 * read (upstream: at module load) from `STRAPI_PLUGIN_I18N_INIT_LOCALE_CODE`.
 */
final class Constants
{
    public const array AUDITED_EVENTS = [
        'LOCALE_CREATE' => 'locale.create',
        'LOCALE_UPDATE' => 'locale.update',
        'LOCALE_DELETE' => 'locale.delete',
        'LOCALE_DEFAULT_UPDATE' => 'locale.default.update',
    ];

    /** @var list<array{code: string, name: string}>|null */
    private static ?array $isoLocales = null;

    /** @return list<array{code: string, name: string}> */
    public static function isoLocales(): array
    {
        if (self::$isoLocales === null) {
            /** @var list<array{code: string, name: string}> $decoded */
            $decoded = json_decode((string) file_get_contents(__DIR__ . '/iso-locales.json'), true, 512, JSON_THROW_ON_ERROR);
            self::$isoLocales = $decoded;
        }

        return self::$isoLocales;
    }

    /**
     * Returns the default locale based either on env var or english
     *
     * @return array{code: string, name: string}
     */
    public static function getInitLocale(): array
    {
        $envLocaleCode = getenv('STRAPI_PLUGIN_I18N_INIT_LOCALE_CODE');
        if (!is_string($envLocaleCode) || $envLocaleCode === '') {
            $fromEnv = $_ENV['STRAPI_PLUGIN_I18N_INIT_LOCALE_CODE'] ?? null;
            $envLocaleCode = is_string($fromEnv) ? $fromEnv : '';
        }

        if ($envLocaleCode !== '') {
            foreach (self::isoLocales() as $locale) {
                if ($locale['code'] === $envLocaleCode) {
                    return [...$locale];
                }
            }

            throw new \RuntimeException('Unknown locale code provided in the environment variable STRAPI_PLUGIN_I18N_INIT_LOCALE_CODE');
        }

        return [
            'code' => 'en',
            'name' => 'English (en)',
        ];
    }

    /** @return array{code: string, name: string} */
    public static function DEFAULT_LOCALE(): array
    {
        return self::getInitLocale();
    }
}
