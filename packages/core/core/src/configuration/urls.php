<?php

declare(strict_types=1);

namespace Strapi\Core\Configuration;

use Strapi\Utils\Primitives\Strings;

/** Port of packages/core/core/src/configuration/urls.ts. */
final class Urls
{
    /**
     * @param array<string, mixed> $config
     * @return array{serverUrl: string, adminUrl: string, adminPath: string}
     */
    public static function getConfigUrls(array $config, bool $forAdminBuild = false): array
    {
        $serverConfig = $config['server'] ?? [];
        $adminConfig = $config['admin'] ?? [];

        // Defines serverUrl value
        $serverUrl = $serverConfig['url'] ?? '';
        if (!is_string($serverUrl)) {
            throw new \RuntimeException('Invalid server url config. Make sure the url is a string.');
        }
        $serverUrl = trim($serverUrl, '/ ');

        if (str_starts_with($serverUrl, 'http')) {
            $normalized = self::normalizeUrl($serverUrl);
            if ($normalized === null) {
                throw new \RuntimeException('Invalid server url config. Make sure the url defined in server.js is valid.');
            }
            $serverUrl = trim($normalized, '/');
        } elseif ($serverUrl !== '') {
            $serverUrl = "/{$serverUrl}";
        }

        // Defines adminUrl value
        $adminUrl = $adminConfig['url'] ?? '/admin';
        if (!is_string($adminUrl)) {
            throw new \RuntimeException('Invalid admin url config. Make sure the url is a non-empty string.');
        }
        $adminUrl = trim($adminUrl, '/ ');
        if (str_starts_with($adminUrl, 'http')) {
            $normalized = self::normalizeUrl($adminUrl);
            if ($normalized === null) {
                throw new \RuntimeException('Invalid admin url config. Make sure the url defined in server.js is valid.');
            }
            $adminUrl = trim($normalized, '/');
        } else {
            $adminUrl = "{$serverUrl}/{$adminUrl}";
        }

        // Defines adminPath value
        $adminPath = $adminUrl;
        if (
            str_starts_with($serverUrl, 'http')
            && str_starts_with($adminUrl, 'http')
            && self::origin($adminUrl) === self::origin($serverUrl)
            && !$forAdminBuild
        ) {
            $adminPath = str_replace(Strings::getCommonPath($serverUrl, $adminUrl), '', $adminUrl);
            $adminPath = '/' . trim($adminPath, '/');
        } elseif (str_starts_with($adminUrl, 'http')) {
            $adminPath = (string) (parse_url($adminUrl, PHP_URL_PATH) ?? '/');
            if ($adminPath === '') {
                $adminPath = '/';
            }
        }

        return ['serverUrl' => $serverUrl, 'adminUrl' => $adminUrl, 'adminPath' => $adminPath];
    }

    /** @param array<string, mixed> $config */
    public static function getAbsoluteAdminUrl(array $config, bool $forAdminBuild = false): string
    {
        return self::getAbsoluteUrl('admin', $config, $forAdminBuild);
    }

    /** @param array<string, mixed> $config */
    public static function getAbsoluteServerUrl(array $config, bool $forAdminBuild = false): string
    {
        return self::getAbsoluteUrl('server', $config, $forAdminBuild);
    }

    /** @param array<string, mixed> $config */
    private static function getAbsoluteUrl(string $adminOrServer, array $config, bool $forAdminBuild): string
    {
        ['serverUrl' => $serverUrl, 'adminUrl' => $adminUrl] = self::getConfigUrls($config, $forAdminBuild);
        $url = $adminOrServer === 'server' ? $serverUrl : $adminUrl;

        if (str_starts_with($url, 'http')) {
            return $url;
        }

        $serverConfig = $config['server'] ?? [];
        $host = (string) ($serverConfig['host'] ?? 'localhost');
        $port = (string) ($serverConfig['port'] ?? '1337');

        $isLocalhost = ($config['environment'] ?? null) === 'development'
            && in_array($host, ['127.0.0.1', '0.0.0.0', '::1', '::'], true);

        if ($isLocalhost) {
            return "http://localhost:{$port}{$url}";
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return "http://[{$host}]:{$port}{$url}";
        }

        return "http://{$host}:{$port}{$url}";
    }

    /** `new URL(url).toString()` — null when the URL does not parse. */
    private static function normalizeUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $out = strtolower($parts['scheme']) . '://';
        if (isset($parts['user'])) {
            $out .= $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@';
        }
        $out .= strtolower($parts['host']);
        if (isset($parts['port'])) {
            $default = ['http' => 80, 'https' => 443][strtolower($parts['scheme'])] ?? null;
            if ($default !== $parts['port']) {
                $out .= ':' . $parts['port'];
            }
        }
        $out .= $parts['path'] ?? '/';
        if (isset($parts['query'])) {
            $out .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $out .= '#' . $parts['fragment'];
        }

        return $out;
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return 'null';
        }

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
