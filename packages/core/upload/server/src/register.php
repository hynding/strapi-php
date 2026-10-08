<?php

declare(strict_types=1);

namespace Strapi\Upload;

use Strapi\Core\Loaders\Plugins\GetEnabledPlugins;
use Strapi\Core\Strapi;
use Strapi\Upload\Migrations\UnsignRichtextAndBlocksUrls;
use Strapi\Upload\Middlewares\Upload as UploadMiddleware;
use Strapi\Upload\Models\AiMetadataJob;

/**
 * Port of server/src/register.ts.
 *
 * `createProvider` resolves `@strapi/provider-upload-<name>` as the installed Composer package
 * `strapi/provider-upload-<name>`, then `<name>` itself (a Composer package name, a class name or
 * a PHP file path relative to the app). A provider package names its entry class in
 * `composer.json` `extra.strapi.main`; the class (or the file's returned object/array) has
 * `init(array $providerOptions, Strapi $strapi)` returning the provider instance. sharp's
 * `cache` / `concurrency` options have no GD counterpart and are ignored.
 */
final class Register
{
    public function __invoke(Strapi $strapi): void
    {
        // Register AI metadata job model
        $strapi->get('models')->add(AiMetadataJob::aiMetadataJob());

        $raw = $strapi->config()->get('plugin::upload') ?? [];
        // createProvider needs a provider; empty get() (e.g. in tests) still has defaults.
        $uploadConfig = [
            'provider' => 'local',
            'providerOptions' => [],
            'actionOptions' => [],
            ...(is_array($raw) ? $raw : []),
        ];

        $strapi->plugin('upload')->provider = self::createProvider($strapi, $uploadConfig);

        // Rewrites richtext / blocks URLs persisted with a (now expired) signature
        $strapi->db()->migrations->internal->register(UnsignRichtextAndBlocksUrls::migration($strapi));

        UploadMiddleware::registerUploadMiddleware($strapi);

        // Register phase, before the MCP HTTP server starts during bootstrap.
        Mcp\RegisterUploadMcpTools::registerUploadMcpTools($strapi);

        if ($strapi->hasPlugin('graphql')) {
            Graphql::installGraphqlExtension($strapi);
        }

        if ($strapi->hasPlugin('documentation')) {
            $spec = self::documentationSpec();
            $override = $strapi->plugin('documentation')->service('override');
            if ($spec !== null && method_exists($override, 'registerOverride')) {
                $override->registerOverride($spec, [
                    'pluginOrigin' => 'upload',
                    'excludeFromGeneration' => ['upload'],
                ]);
            }
        }
    }

    /**
     * `../../documentation/content-api.json`, when shipped
     *
     * @return array<string, mixed>|null
     */
    private static function documentationSpec(): ?array
    {
        $file = dirname(__DIR__, 2) . '/documentation/content-api.json';
        if (!is_file($file)) {
            return null;
        }
        $spec = json_decode((string) file_get_contents($file), true);

        return is_array($spec) ? $spec : null;
    }

    /** @param array<string, mixed> $config */
    public static function createProvider(Strapi $strapi, array $config): Provider
    {
        $providerOptions = is_array($config['providerOptions'] ?? null) ? $config['providerOptions'] : [];
        $actionOptions = is_array($config['actionOptions'] ?? null) ? $config['actionOptions'] : [];

        $providerName = strtolower((string) $config['provider']);

        try {
            $provider = self::requireProvider($strapi, $providerName);
        } catch (\Throwable $err) {
            throw new \RuntimeException("Could not load upload provider \"{$providerName}\".", 0, $err);
        }

        $providerInstance = $provider($providerOptions);

        if (!self::implements($providerInstance, 'delete')) {
            throw new \RuntimeException("The upload provider \"{$providerName}\" doesn't implement the delete method.");
        }

        if (!self::implements($providerInstance, 'upload') && !self::implements($providerInstance, 'uploadStream')) {
            throw new \RuntimeException("The upload provider \"{$providerName}\" doesn't implement the uploadStream nor the upload method.");
        }

        if (!self::implements($providerInstance, 'uploadStream')) {
            $strapi->log()->warning("The upload provider \"{$providerName}\" doesn't implement the uploadStream function. Strapi will fallback on the upload method. Some performance issues may occur.");
        }

        return new Provider($providerInstance, $actionOptions);
    }

    private static function implements(object $instance, string $method): bool
    {
        return method_exists($instance, $method) || (property_exists($instance, $method) && $instance->{$method} instanceof \Closure);
    }

    /**
     * `require(modulePath)`: returns the provider's `init`.
     *
     * @return \Closure(array<string, mixed>): object
     */
    private static function requireProvider(Strapi $strapi, string $providerName): \Closure
    {
        $installed = GetEnabledPlugins::installedPackages($strapi);
        $candidates = ["strapi/provider-upload-{$providerName}", $providerName];

        foreach ($candidates as $packageName) {
            $main = null;
            if (isset($installed[$packageName])) {
                $main = $installed[$packageName]['info']['extra']['strapi']['main'] ?? null;
            } elseif (class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled($packageName)) {
                $path = \Composer\InstalledVersions::getInstallPath($packageName);
                $composer = $path !== null && is_file($path . '/composer.json') ? json_decode((string) file_get_contents($path . '/composer.json'), true) : null;
                $main = is_array($composer) ? ($composer['extra']['strapi']['main'] ?? null) : null;
            }

            if (is_string($main) && $main !== '') {
                return self::initOf($strapi, $main);
            }
        }

        // a class name, or a file relative to the app
        if (class_exists($providerName)) {
            return self::initOf($strapi, $providerName);
        }
        $file = str_starts_with($providerName, '/') ? $providerName : $strapi->dirs()->root . '/' . $providerName;
        foreach ([$file, "{$file}.php", "{$file}/index.php"] as $candidate) {
            if (is_file($candidate)) {
                $module = require $candidate;
                if (is_array($module) && is_callable($module['init'] ?? null)) {
                    $init = $module['init'];

                    return static fn (array $options): object => self::object($init($options, $strapi));
                }
                if (is_object($module) && method_exists($module, 'init')) {
                    return static fn (array $options): object => self::object($module->init($options, $strapi));
                }
                if (is_string($module) && class_exists($module)) {
                    return self::initOf($strapi, $module);
                }
            }
        }

        throw new \RuntimeException("Cannot find module '{$providerName}'");
    }

    /** @return \Closure(array<string, mixed>): object */
    private static function initOf(Strapi $strapi, string $class): \Closure
    {
        if (!class_exists($class) || !method_exists($class, 'init')) {
            throw new \RuntimeException("Cannot find module '{$class}'");
        }

        return static fn (array $options): object => self::object($class::init($options, $strapi));
    }

    private static function object(mixed $instance): object
    {
        if (!is_object($instance)) {
            throw new \RuntimeException('The upload provider init() must return an object');
        }

        return $instance;
    }
}
