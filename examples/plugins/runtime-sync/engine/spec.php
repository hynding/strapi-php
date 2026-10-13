<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Engine;

/**
 * Mirrors engine/spec.js: reads spec/facets.json, the pairing rules both runtimes share.
 *
 * @phpstan-type Facet array{id: string, glob: string, describe?: string, generator?: string, factory?: string, shape?: string, required?: bool}
 */
final class Spec
{
    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    public static function file(): string
    {
        return dirname(__DIR__) . '/spec/facets.json';
    }

    /** @return array<string, mixed> */
    public static function load(): array
    {
        if (self::$spec === null) {
            $decoded = json_decode((string) file_get_contents(self::file()), true, 512, JSON_THROW_ON_ERROR);
            \assert(is_array($decoded));
            self::$spec = $decoded;
        }

        return self::$spec;
    }

    /** @return array{node: list<string>, php: list<string>} */
    public static function extensions(): array
    {
        /** @var array{node: list<string>, php: list<string>} */
        return self::load()['extensions'];
    }

    /** @return 'node'|'php'|null the runtime a file extension belongs to */
    public static function runtimeOfExtension(string $extension): ?string
    {
        foreach (self::extensions() as $runtime => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $runtime;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function ignored(): array
    {
        /** @var list<string> */
        return self::load()['ignore'];
    }

    /** @return list<Facet> */
    public static function paired(): array
    {
        /** @var list<Facet> */
        return self::load()['paired'];
    }

    /** @return list<Facet> */
    public static function shared(): array
    {
        /** @var list<Facet> */
        return self::load()['shared'];
    }

    /** @return list<Facet> */
    public static function unsupported(): array
    {
        /** @var list<Facet> */
        return self::load()['unsupported'];
    }

    /** @return array<string, mixed> */
    public static function manifests(): array
    {
        /** @var array<string, mixed> */
        return self::load()['manifests'];
    }

    /**
     * The paired facet a stem (path without extension) belongs to.
     *
     * @return Facet|null
     */
    public static function pairedFacet(string $stem): ?array
    {
        foreach (self::paired() as $facet) {
            if (Glob::match($facet['glob'], $stem)) {
                return $facet;
            }
        }

        return null;
    }

    /** @return Facet|null */
    public static function sharedFacet(string $path): ?array
    {
        foreach (self::shared() as $facet) {
            if (Glob::match($facet['glob'], $path)) {
                return $facet;
            }
        }

        return null;
    }

    /** @return Facet|null */
    public static function unsupportedFacet(string $path): ?array
    {
        foreach (self::unsupported() as $facet) {
            if (Glob::match($facet['glob'], $path)) {
                return $facet;
            }
        }

        return null;
    }

    public static function isIgnored(string $path): bool
    {
        foreach (self::ignored() as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }
}
