<?php

declare(strict_types=1);

namespace Strapi\Core\Configuration;

use Dotenv\Dotenv;
use Strapi\Utils\EnvHelper;
use Strapi\Utils\Primitives\Objects;
use Strapi\Utils\Primitives\Strings;

/**
 * Port of packages/core/core/src/configuration/index.ts (`loadConfiguration`).
 *
 * Reads `.env` (vlucas/phpdotenv, never overriding variables already in the environment, like
 * `dotenv.config()`), defaults `NODE_ENV` to `development`, loads `config/*.php` merged with
 * `config/env/<NODE_ENV>/*.php`, computes server/admin URLs and the project directories.
 *
 * @phpstan-type StrapiOptions array{appDir: string, distDir?: string, autoReload?: bool, serveAdminPanel?: bool}
 */
final class Configuration
{
    private static ?string $version = null;

    /** The Strapi version this package mirrors (packages/core/core/composer.json). */
    public static function version(): string
    {
        if (self::$version === null) {
            $composer = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);
            self::$version = is_array($composer) && is_string($composer['version'] ?? null) ? $composer['version'] : '0.0.0';
        }

        return self::$version;
    }

    /**
     * The upstream Strapi release this port mirrors (VERSIONING.md): `5.56.0` for `5.56.0`,
     * `5.56.0.1` and `5.56.0-beta.1`. It is what `info.strapi` reports, because everything that
     * reads it talks to the Strapi ecosystem: the admin bundle, data-transfer's version check
     * against Node instances, OpenAPI's x-strapi-version.
     */
    public static function upstreamVersion(): string
    {
        return preg_match('/^v?(\d+\.\d+\.\d+)/', self::version(), $m) === 1 ? $m[1] : self::version();
    }

    /** Load `.env` into the process (idempotent) and return the environment helper. */
    public static function loadEnv(string $appDir): EnvHelper
    {
        $envPath = getenv('ENV_PATH');
        $file = is_string($envPath) && $envPath !== '' ? $envPath : $appDir . '/.env';

        if (is_file($file)) {
            Dotenv::createImmutable(dirname($file), basename($file))->safeLoad();
        }

        if (!is_string(getenv('NODE_ENV')) || getenv('NODE_ENV') === '') {
            $_ENV['NODE_ENV'] = $_SERVER['NODE_ENV'] = 'development';
            putenv('NODE_ENV=development');
        }

        return EnvHelper::fromProcess();
    }

    /**
     * @param StrapiOptions $opts
     * @return array<string, mixed>
     */
    public static function loadConfiguration(array $opts): array
    {
        $appDir = rtrim($opts['appDir'], '/');
        $distDir = rtrim($opts['distDir'] ?? $appDir, '/');
        $autoReload = $opts['autoReload'] ?? false;
        $serveAdminPanel = $opts['serveAdminPanel'] ?? true;

        $env = self::loadEnv($appDir);
        $environment = (string) ($env('NODE_ENV') ?? 'development');

        $pkgJSON = self::readJson($appDir . '/package.json');
        $composerJSON = self::readJson($appDir . '/composer.json');

        $configDir = $distDir . '/config';

        $hostEnv = $env('HOST');
        $portEnv = $env('PORT');
        $defaultHost = is_string($hostEnv) && $hostEnv !== '' ? $hostEnv : ((gethostname() ?: null) ?? 'localhost');
        $defaultPort = is_numeric($portEnv) && (int) $portEnv !== 0 ? (int) $portEnv : 1337;

        $defaultConfig = [
            'server' => ServerConfig::defaults($defaultHost, $defaultPort),
            'admin' => [],
            'api' => ['rest' => ['prefix' => '/api']],
        ];

        $strapiPkg = is_array($pkgJSON['strapi'] ?? null) ? $pkgJSON['strapi'] : [];

        $rootConfig = [
            'launchedAt' => (int) floor(microtime(true) * 1000),
            'autoReload' => $autoReload,
            'environment' => $environment,
            'uuid' => $strapiPkg['uuid'] ?? null,
            'installId' => $strapiPkg['installId'] ?? null,
            'packageJsonStrapi' => Objects::omit($strapiPkg, ['uuid']),
            'info' => [
                ...($pkgJSON !== [] ? $pkgJSON : ['name' => $composerJSON['name'] ?? basename($appDir), 'version' => $composerJSON['version'] ?? '0.0.0']),
                'composer' => $composerJSON,
                'strapi' => self::upstreamVersion(),
                // the strapi-php package version (may carry a pre-release or a fourth number)
                'strapiPhp' => self::version(),
            ],
            'admin' => ['serveAdminPanel' => $serveAdminPanel],
        ];

        // See packages/core/core/src/domain/module/index.ts for plugin config loading
        $baseConfig = Objects::omit(ConfigLoader::load($configDir, $env), ['plugins']); // plugin config will be loaded later

        $envDir = $configDir . '/env/' . $environment;
        $envConfig = ConfigLoader::load($envDir, $env);

        $config = Objects::merge($rootConfig, $defaultConfig, $baseConfig, $envConfig);

        ['serverUrl' => $serverUrl, 'adminUrl' => $adminUrl] = Urls::getConfigUrls($config);

        $serverAbsoluteUrl = Urls::getAbsoluteServerUrl($config);
        $adminAbsoluteUrl = Urls::getAbsoluteAdminUrl($config);

        $sameOrigin = Urls::origin($adminAbsoluteUrl) === Urls::origin($serverAbsoluteUrl);

        $adminPath = $sameOrigin
            ? str_replace(Strings::getCommonPath($serverUrl, $adminUrl), '', $adminUrl)
            : (string) (parse_url($adminUrl, PHP_URL_PATH) ?? '/admin');

        $config = Objects::set($config, 'server.url', $serverUrl);
        $config = Objects::set($config, 'server.absoluteUrl', $serverAbsoluteUrl);
        $config = Objects::set($config, 'admin.url', $adminUrl);
        $config = Objects::set($config, 'admin.path', $adminPath);
        $config = Objects::set($config, 'admin.absoluteUrl', $adminAbsoluteUrl);
        $config['dirs'] = GetDirs::getDirs(['appDir' => $appDir, 'distDir' => $distDir], $config);

        return $config;
    }

    /** @return array<string, mixed> */
    private static function readJson(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }
}
