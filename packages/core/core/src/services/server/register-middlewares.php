<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Server;

use Strapi\Core\Strapi;

/** Port of packages/core/core/src/services/server/register-middlewares.ts. */
final class RegisterMiddlewares
{
    public const DEFAULT_CONFIG = [
        'strapi::logger',
        'strapi::errors',
        'strapi::security',
        'strapi::cors',
        'strapi::poweredBy',
        'strapi::session',
        'strapi::query',
        'strapi::body',
        'strapi::favicon',
        'strapi::public',
    ];

    public const REQUIRED_MIDDLEWARES = [
        'strapi::errors',
        'strapi::security',
        'strapi::cors',
        'strapi::query',
        'strapi::body',
        'strapi::public',
        'strapi::favicon',
    ];

    /** Register middlewares in router. */
    public static function registerApplicationMiddlewares(Strapi $strapi): void
    {
        $middlewareConfig = $strapi->config()->get('middlewares', self::DEFAULT_CONFIG);

        self::validateMiddlewareConfig($middlewareConfig);

        $middlewares = Middleware::resolveMiddlewares($middlewareConfig, $strapi);

        self::checkRequiredMiddlewares($middlewares);

        foreach ($middlewares as $middleware) {
            $strapi->server()->use($middleware['handler']);
        }
    }

    private static function validateMiddlewareConfig(mixed $config): void
    {
        $valid = is_array($config) && array_is_list($config);
        if ($valid) {
            foreach ($config as $item) {
                if (is_string($item)) {
                    continue;
                }
                if (is_array($item)) {
                    $unknown = array_diff(array_keys($item), ['name', 'resolve', 'config']);
                    if ($unknown !== []) {
                        $valid = false;
                        break;
                    }
                    continue;
                }
                $valid = false;
                break;
            }
        }

        if (!$valid) {
            throw new \RuntimeException('Invalid middleware configuration. Expected Array<string|{name?: string, resolve?: string, config: any}.');
        }
    }

    /** @param list<array{name: string|null, handler: callable}> $middlewares */
    private static function checkRequiredMiddlewares(array $middlewares): void
    {
        $names = array_map(static fn (array $m): ?string => $m['name'], $middlewares);
        $missing = array_values(array_filter(self::REQUIRED_MIDDLEWARES, static fn (string $name): bool => !in_array($name, $names, true)));

        if ($missing !== []) {
            throw new \RuntimeException('Missing required middlewares in configuration. Add the following middlewares: "' . implode(', ', $missing) . '".');
        }
    }
}
