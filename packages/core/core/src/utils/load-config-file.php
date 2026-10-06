<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

use Strapi\Utils\EnvHelper;

/**
 * Port of packages/core/core/src/utils/load-config-file.ts.
 *
 * A `.php` config file `return`s either an array or a `callable(EnvHelper $env): array`
 * (the `({ env }) => ({...})` form upstream). `.json` files are decoded as is.
 */
final class LoadConfigFile
{
    public static function loadConfigFile(string $file, ?EnvHelper $env = null): mixed
    {
        return match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'php' => self::loadPhpFile($file, $env ?? EnvHelper::fromProcess()),
            'json' => self::loadJsonFile($file),
            default => [],
        };
    }

    private static function loadPhpFile(string $file, EnvHelper $env): mixed
    {
        try {
            $module = (static fn (): mixed => require $file)();

            // call if function
            if (is_callable($module) && !is_string($module)) {
                return $module($env);
            }

            return $module;
        } catch (\Throwable $error) {
            throw new \RuntimeException("Could not load php config file {$file}: {$error->getMessage()}", 0, $error);
        }
    }

    private static function loadJsonFile(string $file): mixed
    {
        try {
            return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new \RuntimeException("Could not load json config file {$file}: {$error->getMessage()}", 0, $error);
        }
    }
}
