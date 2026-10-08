<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Utils;

use Strapi\Core\Strapi;

/**
 * Not an upstream file: `strapi.plugin('upload').provider` and `strapi.config.get('plugin::upload').provider`
 * as the data transfer uses them (the provider is the upload plugin's wrapper object, whose
 * methods are the provider's).
 */
final class UploadProvider
{
    public static function configuredName(Strapi $strapi): ?string
    {
        $config = $strapi->config()->get('plugin::upload');
        $provider = is_array($config) ? ($config['provider'] ?? null) : null;

        return is_string($provider) ? $provider : null;
    }

    public static function instance(Strapi $strapi): object
    {
        $provider = $strapi->plugin('upload')->provider;
        if (!is_object($provider)) {
            throw new \RuntimeException('The upload provider is not available');
        }

        return $provider;
    }

    /** Call a provider method (`uploadStream`, `delete`, `isPrivate`, `getSignedUrl`, …). */
    public static function call(Strapi $strapi, string $method, mixed ...$args): mixed
    {
        $provider = self::instance($strapi);

        return $provider->{$method}(...$args);
    }

    public static function isPrivate(Strapi $strapi): bool
    {
        $provider = $strapi->plugin('upload')->provider;

        return is_object($provider) && method_exists($provider, 'isPrivate') && (bool) $provider->isPrivate();
    }

    /**
     * @param array<string, mixed>|\ArrayAccess<string, mixed> $file
     */
    public static function signedUrl(Strapi $strapi, array|\ArrayAccess $file): ?string
    {
        $signed = self::call($strapi, 'getSignedUrl', $file);
        $url = is_array($signed) || $signed instanceof \ArrayAccess ? ($signed['url'] ?? null) : null;

        return is_string($url) ? $url : null;
    }
}
