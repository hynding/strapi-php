<?php

declare(strict_types=1);

namespace Strapi\Core\Configuration;

use Psr\Log\LoggerInterface;
use Strapi\Types\Modules\Config\Config;

/**
 * Port of packages/core/core/src/configuration/server-config.ts: the server defaults and the
 * one-time deprecation warnings.
 */
final class ServerConfig
{
    /** @return array<string, mixed> */
    public static function defaults(string $host, int $port): array
    {
        return [
            'host' => $host,
            'port' => $port,
            'proxy' => false,
            'cron' => ['enabled' => false],
            'dirs' => ['public' => './public'],
            'transfer' => ['remote' => ['enabled' => true]],
            'logger' => [
                'updates' => ['enabled' => true],
                'startup' => ['enabled' => true],
            ],
            'openapi' => [
                'content-api' => [
                    'access' => 'disabled',
                    'route' => ['path' => '/openapi.json'],
                    'cache' => ['enabled' => true, 'maxAgeMs' => 60_000, 'filePath' => '.strapi/openapi/content-api.json'],
                ],
                'admin' => [
                    'access' => 'disabled',
                    'route' => ['path' => '/openapi.json'],
                    'cache' => ['enabled' => true, 'maxAgeMs' => 60_000, 'filePath' => '.strapi/openapi/admin.json'],
                ],
            ],
        ];
    }

    /**
     * Log once at startup when deprecated server config keys are present.
     * These keys are not read; warnings point users to the supported alternatives.
     */
    public static function warnDeprecatedServerConfig(Config $config, LoggerInterface $log): void
    {
        if ($config->get('server.globalProxy')) {
            $log->warning('server.globalProxy is deprecated and ignored. Use server.proxy.global in config/server instead.');
        }

        if ($config->get('server.admin.autoOpen') !== null) {
            $log->warning('server.admin.autoOpen is deprecated and ignored. Use admin.autoOpen in config/admin instead.');
        }
    }
}
