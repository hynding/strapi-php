<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Engine;

/**
 * Mirrors engine/project.js: what the manifests say about a project. Which runtimes it is set
 * up for, at which Strapi version, its `.env`, its admin build. Nothing here boots Strapi.
 *
 * @phpstan-type Inspection array{
 *   root: string, name: string,
 *   node: array{present: bool, version: ?string, upstreamVersion: ?string, language: 'js'|'ts', installed: bool},
 *   php: array{present: bool, version: ?string, upstreamVersion: ?string, installed: bool},
 *   packageJson: ?array<string, mixed>, composerJson: ?array<string, mixed>,
 *   env: array{present: bool, keys: list<string>, databaseClient: string},
 *   adminBuild: array{present: bool, dir: ?string}
 * }
 */
final class Project
{
    /** @return Inspection */
    public static function inspect(string $root): array
    {
        $packageJson = Files::readJson($root . '/package.json');
        $composerJson = Files::readJson($root . '/composer.json');

        $nodeDeps = self::dependencies($packageJson);
        $nodeStrapi = $nodeDeps['@strapi/strapi'] ?? null;
        $nodeFiles = self::hasRuntimeFiles($root, 'node');
        $nodeVersion = is_string($nodeStrapi) ? self::cleanVersion($nodeStrapi) : null;

        $phpRequire = is_array($composerJson['require'] ?? null) ? $composerJson['require'] : [];
        $phpConstraint = $phpRequire['hynding/strapi-php'] ?? $phpRequire['strapi/strapi'] ?? $phpRequire['strapi/core'] ?? null;
        $phpInstalled = self::installedComposerVersion($root);
        $phpVersion = $phpInstalled ?? (is_string($phpConstraint) ? self::cleanVersion($phpConstraint) : null);

        $env = self::readEnv($root . '/.env');

        $adminBuildDir = null;
        foreach (['build', 'dist/build'] as $candidate) {
            if (is_file($root . '/' . $candidate . '/index.html')) {
                $adminBuildDir = $candidate;
                break;
            }
        }

        $name = is_string($packageJson['name'] ?? null) ? $packageJson['name'] : (is_string($composerJson['name'] ?? null) ? $composerJson['name'] : basename($root));

        return [
            'root' => $root,
            'name' => $name,
            'node' => [
                'present' => $nodeStrapi !== null && $nodeFiles,
                'version' => $nodeVersion,
                'upstreamVersion' => $nodeVersion === null ? null : self::upstreamVersion($nodeVersion),
                'language' => is_file($root . '/tsconfig.json') || is_file($root . '/src/index.ts') ? 'ts' : 'js',
                'installed' => is_dir($root . '/node_modules/@strapi/strapi'),
            ],
            'php' => [
                'present' => $phpConstraint !== null && self::hasRuntimeFiles($root, 'php'),
                'version' => $phpVersion,
                'upstreamVersion' => $phpVersion === null ? null : self::upstreamVersion($phpVersion),
                'installed' => $phpInstalled !== null,
            ],
            'packageJson' => $packageJson,
            'composerJson' => $composerJson,
            'env' => $env,
            'adminBuild' => ['present' => $adminBuildDir !== null, 'dir' => $adminBuildDir],
        ];
    }

    /**
     * A runtime is "present" when the project has that runtime's own files: config or the app entry.
     *
     * @param 'node'|'php' $runtime
     */
    public static function hasRuntimeFiles(string $root, string $runtime): bool
    {
        foreach (Spec::extensions()[$runtime] as $ext) {
            if (is_file("{$root}/src/index.{$ext}") || glob("{$root}/config/*.{$ext}") !== [] && glob("{$root}/config/*.{$ext}") !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * dependencies + devDependencies of a package.json.
     *
     * @param array<string, mixed>|null $packageJson
     * @return array<string, mixed>
     */
    public static function dependencies(?array $packageJson): array
    {
        $deps = is_array($packageJson['devDependencies'] ?? null) ? $packageJson['devDependencies'] : [];
        $prod = is_array($packageJson['dependencies'] ?? null) ? $packageJson['dependencies'] : [];

        return [...$deps, ...$prod];
    }

    /** `^5.56.0`, `~5.56`, `=v5.56.0`, `5.56.0-beta.1` → without the range operator. */
    public static function cleanVersion(string $constraint): string
    {
        return trim(ltrim(trim($constraint), '^~>=v '));
    }

    /**
     * The upstream Strapi release of a version (VERSIONING.md): `5.56.0` for `5.56.0`, `5.56.0.1`
     * and `5.56.0-beta.1`; `5.56` stays `5.56` (a Composer range floor).
     */
    public static function upstreamVersion(string $version): string
    {
        return preg_match('/^(\d+\.\d+(?:\.\d+)?)/', $version, $m) === 1 ? $m[1] : $version;
    }

    /**
     * The most precise of several upstream versions: a full `x.y.z` over a `x.y` range floor.
     *
     * @param list<?string> $versions
     */
    public static function bestVersion(array $versions): ?string
    {
        $known = array_values(array_filter($versions, is_string(...)));
        foreach ($known as $version) {
            if (preg_match('/^\d+\.\d+\.\d+/', $version) === 1) {
                return $version;
            }
        }

        return $known[0] ?? null;
    }

    /** Whether two upstream versions name the same release (`5.56` is the floor of `5.56.0`). */
    public static function versionsAgree(string $a, string $b): bool
    {
        return $a === $b || str_starts_with($a, $b . '.') || str_starts_with($b, $a . '.');
    }

    /** The installed `hynding/strapi-php` (or `strapi/strapi`) version from vendor/composer/installed.json. */
    public static function installedComposerVersion(string $root): ?string
    {
        $installed = Files::readJson($root . '/vendor/composer/installed.json');
        $list = is_array($installed['packages'] ?? null) ? $installed['packages'] : ($installed ?? []);
        foreach (['hynding/strapi-php', 'strapi/strapi'] as $name) {
            foreach ($list as $package) {
                if (is_array($package) && ($package['name'] ?? null) === $name && is_string($package['version'] ?? null)) {
                    return self::cleanVersion($package['version']);
                }
            }
        }

        return null;
    }

    /**
     * The keys of a `.env` file and the database client it selects.
     *
     * @return array{present: bool, keys: list<string>, databaseClient: string}
     */
    public static function readEnv(string $file): array
    {
        if (!is_file($file)) {
            return ['present' => false, 'keys' => [], 'databaseClient' => 'sqlite'];
        }

        $keys = [];
        $values = [];
        foreach (preg_split('/\r?\n/', (string) file_get_contents($file)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim((string) preg_replace('/^export\s+/', '', $key));
            $keys[] = $key;
            $values[$key] = trim(trim($value), '"\'');
        }

        $client = $values['DATABASE_CLIENT'] ?? 'sqlite';

        return ['present' => true, 'keys' => array_values(array_unique($keys)), 'databaseClient' => $client === '' ? 'sqlite' : $client];
    }
}
