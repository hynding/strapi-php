<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Server;

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

/**
 * Port of packages/core/core/src/services/server/openapi.ts (`registerOpenAPIRoute`): the
 * `/openapi.json` endpoints configured under `server.openapi` (`content-api`: `disabled` or
 * `public`; `admin`: `disabled` or `authenticated`), serving `Strapi\Openapi\Exports::generate()`
 * with an optional file cache.
 *
 * @phpstan-type ResolvedEndpointConfig array{type: 'content-api'|'admin', access: string, routePath: string, routerPrefix: string|null, fullPath: string, cacheEnabled: bool, cacheMaxAgeMs: int, absoluteCachePath: string}
 */
final class Openapi
{
    /**
     * `@strapi/openapi`'s `generate(strapi, { type })`; tests replace it (upstream mocks the module).
     *
     * @var (\Closure(object, array{type: 'content-api'|'admin'}): array{document: array<string, mixed>, durationMs: int})|null
     */
    public static ?\Closure $generate = null;

    private const SUPPORTED_ACCESS = [
        'content-api' => ['disabled', 'public'],
        'admin' => ['disabled', 'authenticated'],
    ];

    private const DEFAULT_ROUTE_PATH = '/openapi.json';

    private const DEFAULT_ACCESS = 'disabled';

    private const DEFAULT_CACHE_ENABLED = true;

    private const DEFAULT_CACHE_MAX_AGE_MS = 60_000;

    private const DEFAULT_CACHE_RELATIVE_FILE_PATHS = [
        'content-api' => '.strapi/openapi/content-api.json',
        'admin' => '.strapi/openapi/admin.json',
    ];

    public static function normalizePath(string $value): string
    {
        if ($value === '') {
            return self::DEFAULT_ROUTE_PATH;
        }

        return str_starts_with($value, '/') ? $value : "/{$value}";
    }

    public static function joinPaths(string $basePath, string $routePath): string
    {
        $normalizedBasePath = self::normalizePath($basePath !== '' ? $basePath : '/');
        $normalizedRoutePath = self::normalizePath($routePath !== '' ? $routePath : self::DEFAULT_ROUTE_PATH);

        $trimmedBasePath = $normalizedBasePath === '/' ? '' : (string) preg_replace('/\/+$/', '', $normalizedBasePath);

        $joined = $trimmedBasePath . $normalizedRoutePath;

        return $joined !== '' ? $joined : '/';
    }

    public static function stripBasePrefix(string $routePath, string $basePath): string
    {
        $normalizedBasePath = self::normalizePath($basePath !== '' ? $basePath : '/');
        $normalizedRoutePath = self::normalizePath($routePath !== '' ? $routePath : self::DEFAULT_ROUTE_PATH);

        if ($normalizedBasePath === '/') {
            return $normalizedRoutePath;
        }

        if ($normalizedRoutePath === $normalizedBasePath) {
            return '/';
        }

        if (str_starts_with($normalizedRoutePath, "{$normalizedBasePath}/")) {
            $strippedPath = substr($normalizedRoutePath, strlen($normalizedBasePath));

            return self::normalizePath($strippedPath);
        }

        return $normalizedRoutePath;
    }

    /**
     * @param Strapi $strapi
     * @param array<string, mixed>|null $rawConfig
     * @param 'content-api'|'admin' $type
     *
     * @return ResolvedEndpointConfig
     */
    public static function resolveEndpointConfig(object $strapi, ?array $rawConfig, string $type): array
    {
        $apiPrefix = (string) $strapi->config()->get('api.rest.prefix', '/api');
        $adminPath = (string) $strapi->config()->get('admin.path', '/admin');
        $basePath = $type === 'content-api' ? $apiPrefix : $adminPath;

        $configuredPath = (string) ($rawConfig['route']['path'] ?? self::DEFAULT_ROUTE_PATH);
        $routePath = self::stripBasePrefix($configuredPath, $basePath);
        $routerPrefix = $type === 'admin' ? self::normalizePath($basePath) : null;
        $fullPath = $type === 'content-api'
            ? self::joinPaths(self::normalizePath($apiPrefix), $routePath)
            : self::joinPaths((string) $routerPrefix, $routePath);
        $access = (string) ($rawConfig['access'] ?? self::DEFAULT_ACCESS);
        $supportedAccess = self::SUPPORTED_ACCESS[$type];

        if (!in_array($access, $supportedAccess, true)) {
            throw new \RuntimeException("Invalid OpenAPI access \"{$access}\" for \"{$type}\". Expected one of: " . implode(', ', $supportedAccess));
        }

        $cacheEnabled = (bool) ($rawConfig['cache']['enabled'] ?? self::DEFAULT_CACHE_ENABLED);
        $cacheMaxAgeMs = (int) ($rawConfig['cache']['maxAgeMs'] ?? self::DEFAULT_CACHE_MAX_AGE_MS);
        $configuredCachePath = (string) ($rawConfig['cache']['filePath'] ?? self::DEFAULT_CACHE_RELATIVE_FILE_PATHS[$type]);
        $absoluteCachePath = str_starts_with($configuredCachePath, '/')
            ? $configuredCachePath
            : rtrim($strapi->dirs()->root, '/') . '/' . $configuredCachePath;

        return [
            'type' => $type,
            'access' => $access,
            'routePath' => $routePath,
            'routerPrefix' => $routerPrefix,
            'fullPath' => $fullPath,
            'cacheEnabled' => $cacheEnabled,
            'cacheMaxAgeMs' => $cacheMaxAgeMs,
            'absoluteCachePath' => $absoluteCachePath,
        ];
    }

    /**
     * Build the route `config` (auth + policies) for an endpoint.
     *
     * - `admin`: requires an authenticated admin via the `admin::isAuthenticatedAdmin` policy.
     *   Granular per-permission RBAC is intentionally left for a later iteration.
     * - `content-api`: only supports public access for now, which disables auth so anyone can
     *   read the spec.
     *
     * @param ResolvedEndpointConfig $config
     *
     * @return array<string, mixed>
     */
    private static function buildRouteConfig(array $config): array
    {
        if ($config['type'] === 'admin') {
            return ['policies' => ['admin::isAuthenticatedAdmin']];
        }

        return ['auth' => false];
    }

    /**
     * @param Strapi $strapi
     *
     * @return list<ResolvedEndpointConfig>
     */
    private static function resolveOpenAPIConfig(object $strapi): array
    {
        $rawConfig = $strapi->config()->get('server.openapi', []);
        $rawConfig = is_array($rawConfig) ? $rawConfig : [];

        $contentApi = is_array($rawConfig['content-api'] ?? null) ? $rawConfig['content-api'] : null;
        $admin = is_array($rawConfig['admin'] ?? null) ? $rawConfig['admin'] : null;

        return array_values(array_filter(
            [
                self::resolveEndpointConfig($strapi, $contentApi, 'content-api'),
                self::resolveEndpointConfig($strapi, $admin, 'admin'),
            ],
            static fn (array $config): bool => $config['access'] !== 'disabled',
        ));
    }

    /** The cached document (decoded with JSON objects as `\stdClass`), or null when stale or missing. */
    private static function readCache(string $cachePath, int $maxAgeMs): mixed
    {
        if ($maxAgeMs < 0) {
            return null;
        }

        $mtime = @filemtime($cachePath);
        if ($mtime === false) {
            return null;
        }

        $ageMs = microtime(true) * 1000 - $mtime * 1000;
        if ($ageMs > $maxAgeMs) {
            return null;
        }

        $raw = @file_get_contents($cachePath);
        if ($raw === false) {
            return null;
        }

        $document = json_decode($raw);

        // fs.readJson() throws on invalid JSON: treated as a cache miss
        return json_last_error() === JSON_ERROR_NONE ? $document : null;
    }

    /** `fs.outputJson(cachePath, document, { spaces: 2 })` */
    private static function writeCache(string $cachePath, mixed $document): void
    {
        $dir = dirname($cachePath);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("EACCES: permission denied, mkdir '{$dir}'");
        }

        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        $json = (string) preg_replace_callback('/^( {4})+/m', static fn (array $m): string => str_repeat('  ', intdiv(strlen($m[0]), 4)), $json);

        if (@file_put_contents($cachePath, $json . "\n") === false) {
            throw new \RuntimeException("EACCES: permission denied, open '{$cachePath}'");
        }
    }

    /**
     * @param Strapi $strapi
     * @param 'content-api'|'admin' $type
     *
     * @return array<string, mixed>
     */
    private static function generateDocument(object $strapi, string $type): array
    {
        if (self::$generate !== null) {
            return (self::$generate)($strapi, ['type' => $type])['document'];
        }

        if (!class_exists(\Strapi\Openapi\Exports::class)) {
            throw new \RuntimeException('The strapi/openapi package is not installed');
        }

        return \Strapi\Openapi\Exports::generate($strapi, ['type' => $type])['document'];
    }

    /**
     * @param Strapi $strapi typed structurally (upstream tests pass a partial mock)
     */
    public static function registerOpenAPIRoute(object $strapi): void
    {
        $configs = self::resolveOpenAPIConfig($strapi);

        if ($configs === []) {
            return;
        }

        $fullPathSet = [];

        foreach ($configs as $config) {
            if (isset($fullPathSet[$config['fullPath']])) {
                throw new \RuntimeException("Duplicate OpenAPI endpoint path detected: \"{$config['fullPath']}\"");
            }

            $fullPathSet[$config['fullPath']] = true;
        }

        foreach ($configs as $config) {
            $handler = static function (Context $ctx) use ($strapi, $config): void {
                try {
                    if ($config['cacheEnabled']) {
                        $cachedDocument = self::readCache($config['absoluteCachePath'], $config['cacheMaxAgeMs']);

                        if ($cachedDocument !== null && $cachedDocument !== false) {
                            $ctx->setHeader('Content-Type', 'application/json');
                            $ctx->setBody($cachedDocument);

                            return;
                        }
                    }

                    $document = self::generateDocument($strapi, $config['type']);

                    if ($config['cacheEnabled']) {
                        self::writeCache($config['absoluteCachePath'], $document);
                    }

                    $ctx->setHeader('Content-Type', 'application/json');
                    $ctx->setBody($document);
                } catch (\Throwable $error) {
                    $strapi->log()->error($error->getMessage(), ['exception' => $error]);
                    $ctx->internalServerError('Failed to generate OpenAPI document');
                }
            };

            $router = [
                'type' => $config['type'],
                ...($config['routerPrefix'] !== null && $config['routerPrefix'] !== '' ? ['prefix' => $config['routerPrefix']] : []),
                'routes' => [
                    [
                        'method' => 'GET',
                        'path' => $config['routePath'],
                        'handler' => $handler,
                        'info' => [
                            'type' => $config['type'],
                        ],
                        'config' => self::buildRouteConfig($config),
                    ],
                ],
            ];

            $strapi->server()->routes($router);
        }
    }
}
