<?php

declare(strict_types=1);

namespace Strapi\Core\Registries;

/** Port of packages/core/core/src/registries/namespace.ts. */
final class Namespace_
{
    public static function hasNamespace(string $name, string $namespace): bool
    {
        if ($namespace === '') {
            return true;
        }

        if (str_ends_with($namespace, '::')) {
            return str_starts_with($name, $namespace);
        }

        return str_starts_with($name, "{$namespace}.");
    }

    public static function addNamespace(string $name, string $namespace): string
    {
        if (str_ends_with($namespace, '::')) {
            return "{$namespace}{$name}";
        }

        return "{$namespace}.{$name}";
    }

    public static function removeNamespace(string $name, string $namespace): string
    {
        if (str_ends_with($namespace, '::')) {
            return str_replace($namespace, '', $name);
        }

        return str_replace("{$namespace}.", '', $name);
    }
}
