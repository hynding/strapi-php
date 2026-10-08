<?php

declare(strict_types=1);

namespace Strapi\Email;

use Strapi\Core\Loaders\Plugins\GetEnabledPlugins;
use Strapi\Core\Strapi;

/**
 * Port of server/src/bootstrap.ts.
 *
 * `createProvider` resolves `@strapi/provider-email-<name>` as the installed Composer package
 * `strapi/provider-email-<name>`, then `<name>` itself (a Composer package name, a class name or a
 * PHP file path relative to the app), as the upload plugin does. A provider package names its
 * entry class in `composer.json` `extra.strapi.main`; the class (or the file's returned
 * object/array) has `init(array $providerOptions, array $settings, Strapi $strapi)` returning the
 * provider instance (`send()`, and optionally `verify()`, `isIdle()`, `close()`,
 * `getCapabilities()`). Upstream providers read nothing ambient; the third argument lets a PHP
 * provider reach `strapi.fetch`.
 */
final class Bootstrap
{
    public function __invoke(Strapi $strapi): void
    {
        $raw = $strapi->config()->get('plugin::email');
        /** @var array<string, mixed> $emailConfig */
        $emailConfig = is_array($raw) ? $raw : [];

        $providerName = strtolower((string) ($emailConfig['provider'] ?? ''));
        $isDevelopment = getenv('NODE_ENV') === 'development';
        if ($providerName === 'sendmail' && $isDevelopment) {
            $strapi->log()->warning(
                '[email]: The "sendmail" email provider is still supported, but for most production setups that use a dedicated SMTP relay, consider switching to @strapi/provider-email-nodemailer (set `provider` to `"nodemailer"` in your email plugin config). This message is only shown in development.'
            );
        }

        $strapi->plugin('email')->provider = self::createProvider($strapi, $emailConfig);

        // Add permissions
        $actions = [
            [
                'section' => 'settings',
                'category' => 'email',
                'displayName' => 'Access the Email Settings page',
                'uid' => 'settings.read',
                'pluginName' => 'email',
            ],
        ];

        $permission = $strapi->service('admin::permission');
        $registerMany = property_exists($permission, 'actionProvider') && is_object($permission->actionProvider)
            ? [$permission->actionProvider, 'registerMany']
            : null;
        if (!is_callable($registerMany)) {
            throw new \RuntimeException('The admin::permission service has no actionProvider');
        }
        $registerMany($actions);
    }

    /** @param array<string, mixed> $emailConfig */
    public static function createProvider(Strapi $strapi, array $emailConfig): object
    {
        $providerName = strtolower((string) ($emailConfig['provider'] ?? ''));
        $providerOptions = is_array($emailConfig['providerOptions'] ?? null) ? $emailConfig['providerOptions'] : [];
        $settings = is_array($emailConfig['settings'] ?? null) ? $emailConfig['settings'] : [];

        try {
            $init = self::requireProvider($strapi, $providerName);
        } catch (\Throwable $err) {
            throw new \RuntimeException("Could not load email provider \"{$providerName}\".", 0, $err);
        }

        $instance = $init($providerOptions, $settings);
        if (!is_object($instance)) {
            throw new \RuntimeException("The email provider \"{$providerName}\" init() must return an object");
        }

        return $instance;
    }

    /**
     * `require(modulePath)`: returns the provider's `init`.
     *
     * @return \Closure(array<string, mixed>, array<string, mixed>): mixed
     */
    private static function requireProvider(Strapi $strapi, string $providerName): \Closure
    {
        $installed = GetEnabledPlugins::installedPackages($strapi);
        $candidates = ["strapi/provider-email-{$providerName}", $providerName];

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
        if ($providerName !== '' && class_exists($providerName)) {
            return self::initOf($strapi, $providerName);
        }
        $file = str_starts_with($providerName, '/') ? $providerName : $strapi->dirs()->root . '/' . $providerName;
        foreach ([$file, "{$file}.php", "{$file}/index.php"] as $candidate) {
            if ($providerName !== '' && is_file($candidate)) {
                $module = require $candidate;
                if (is_array($module) && is_callable($module['init'] ?? null)) {
                    $init = $module['init'];

                    return static fn (array $options, array $settings): mixed => $init($options, $settings, $strapi);
                }
                if (is_object($module) && method_exists($module, 'init')) {
                    return static fn (array $options, array $settings): mixed => $module->init($options, $settings, $strapi);
                }
                if (is_string($module) && class_exists($module)) {
                    return self::initOf($strapi, $module);
                }
            }
        }

        throw new \RuntimeException("Cannot find module '{$providerName}'");
    }

    /** @return \Closure(array<string, mixed>, array<string, mixed>): mixed */
    private static function initOf(Strapi $strapi, string $class): \Closure
    {
        if (!class_exists($class) || !method_exists($class, 'init')) {
            throw new \RuntimeException("Cannot find module '{$class}'");
        }

        return static fn (array $options, array $settings): mixed => $class::init($options, $settings, $strapi);
    }
}
