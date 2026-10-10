<?php

declare(strict_types=1);

namespace Strapi\ApiTests;

use Strapi\Core\Strapi;
use Strapi\Upload\Provider;

/**
 * A suite that `jest.mock()`s `@strapi/provider-upload-local` (core/upload upload-signing) expects
 * Strapi to load that mock: lib/strapi.js calls `init()` on it and hands the methods it returned
 * here as {@see Callback}s. They become the upload plugin's provider, a real {@see Provider} (the
 * upload services check `instanceof` it for `isPrivate()` / `getSignedUrl()`), and what they
 * change in a file (`file.url = ...`) is copied back as with a {@see RemoteObject}.
 */
final class UploadProviderMock
{
    /** @param array<string, mixed> $methods method name => Callback */
    public static function install(Strapi $strapi, array $methods): bool
    {
        $callbacks = array_filter($methods, static fn (mixed $method): bool => $method instanceof Callback);
        $remote = new RemoteObject(null, $callbacks);

        $instance = new \stdClass();
        foreach (array_keys($callbacks) as $name) {
            $instance->{$name} = static fn (mixed ...$args): mixed => $remote->{$name}(...$args);
        }

        $strapi->plugin('upload')->provider = new Provider($instance);

        return true;
    }
}
