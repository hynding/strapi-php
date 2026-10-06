<?php

declare(strict_types=1);

namespace Strapi\Core\Loaders;

use Strapi\Core\Middlewares\Middlewares as InternalMiddlewares;
use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/loaders/middlewares.ts: `src/middlewares/<name>.php` (each file
 * returns a middleware factory) under `global::`, plus the internal `strapi::` middlewares.
 */
final class Middlewares
{
    public function __invoke(Strapi $strapi): void
    {
        self::loadMiddlewares($strapi);
    }

    public static function loadMiddlewares(Strapi $strapi): void
    {
        $strapi->get('middlewares')->add('global::', self::loadLocalMiddlewares($strapi));
        $strapi->get('middlewares')->add('strapi::', InternalMiddlewares::all());
    }

    /** @return array<string, callable> */
    private static function loadLocalMiddlewares(Strapi $strapi): array
    {
        $dir = $strapi->dirs()->middlewares;
        if (!is_dir($dir)) {
            return [];
        }

        $middlewares = [];
        $entries = scandir($dir) ?: [];
        sort($entries);
        foreach ($entries as $name) {
            $fullPath = $dir . '/' . $name;
            if (is_file($fullPath) && pathinfo($name, PATHINFO_EXTENSION) === 'php') {
                $key = pathinfo($name, PATHINFO_FILENAME);
                $factory = (static fn (): mixed => require $fullPath)();
                if (!is_callable($factory)) {
                    throw new \RuntimeException("Middleware file {$fullPath} must return a callable factory");
                }
                $middlewares[$key] = $factory;
            }
        }

        return $middlewares;
    }
}
