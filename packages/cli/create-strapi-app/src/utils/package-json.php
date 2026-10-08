<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Utils;

use Strapi\CreateStrapiApp\Types;

/**
 * Port of packages/cli/create-strapi-app/src/utils/package-json.ts.
 *
 * In a PHP project `package.json` only serves the admin panel build: it pins `@strapi/admin` and
 * the plugins' admin packages to the upstream release (VERSIONING.md rule 2) and carries the
 * `strapi` key (`uuid`, `installId`, `template`) that strapi/core reads like upstream does.
 * lodash `kebabCase` / `mergeWith` and `sort-package-json` are ported below.
 *
 * @phpstan-import-type Scope from Types
 */
final class PackageJson
{
    /** sort-package-json's key order (the part that matters for a Strapi project). */
    private const KEY_ORDER = [
        '$schema', 'name', 'displayName', 'version', 'private', 'description', 'categories', 'keywords',
        'homepage', 'bugs', 'repository', 'funding', 'license', 'qna', 'author', 'maintainers',
        'contributors', 'publisher', 'sideEffects', 'type', 'imports', 'exports', 'main', 'svelte',
        'umd:main', 'jsdelivr', 'unpkg', 'module', 'source', 'jsnext:main', 'browser', 'react-native',
        'types', 'typesVersions', 'typings', 'style', 'example', 'examplestyle', 'assets', 'bin', 'man',
        'directories', 'files', 'workspaces', 'binary', 'scripts', 'betterScripts', 'contributes',
        'activationEvents', 'husky', 'simple-git-hooks', 'pre-commit', 'commitlint', 'lint-staged',
        'config', 'nodemonConfig', 'browserify', 'babel', 'browserslist', 'xo', 'prettier',
        'eslintConfig', 'eslintIgnore', 'npmpackagejsonlint', 'release', 'remarkConfig', 'stylelint',
        'ava', 'jest', 'mocha', 'nyc', 'tap', 'oclif', 'resolutions', 'dependencies', 'devDependencies',
        'dependenciesMeta', 'peerDependencies', 'peerDependenciesMeta', 'optionalDependencies',
        'bundledDependencies', 'bundleDependencies', 'extensionPack', 'extensionDependencies', 'flat',
        'packageManager', 'engines', 'engineStrict', 'volta', 'languageName', 'os', 'cpu', 'preferGlobal',
        'publishConfig', 'icon', 'badges', 'galleryBanner', 'preview', 'markdown', 'pnpm',
    ];

    /** Keys whose object value sort-package-json sorts alphabetically. */
    private const SORTED_OBJECTS = [
        'dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies', 'resolutions',
        'engines', 'peerDependenciesMeta', 'dependenciesMeta',
    ];

    /**
     * @param array<string, mixed> $existingPkg
     * @param array<string, mixed> $pkg
     * @return array<string, mixed>
     */
    public static function mergePackageJson(array $existingPkg, array $pkg): array
    {
        return self::mergeWith($existingPkg, $pkg);
    }

    /**
     * lodash `mergeWith({}, a, b, customizer)` where the customizer replaces arrays: objects merge
     * recursively, everything else (lists included) is overwritten.
     *
     * @param array<array-key, mixed> $target
     * @param array<array-key, mixed> $source
     * @return array<array-key, mixed>
     */
    public static function mergeWith(array $target, array $source): array
    {
        foreach ($source as $key => $value) {
            if (is_array($value) && !array_is_list($value) && is_array($target[$key] ?? null) && !array_is_list($target[$key])) {
                $target[$key] = self::mergeWith($target[$key], $value);
            } else {
                $target[$key] = $value;
            }
        }

        return $target;
    }

    /**
     * @param Scope $scope
     * @param array<string, mixed> $existingPkg
     * @return array<string, mixed>
     */
    private static function getPnpmPackageJsonConfig(array $scope, array $existingPkg): array
    {
        if ($scope['packageManager'] !== 'pnpm' || !PnpmConfig::shouldUsePackageJsonPnpmConfig($scope['pnpmVersion'])) {
            return [];
        }

        $existingPnpm = is_array($existingPkg['pnpm'] ?? null) ? $existingPkg['pnpm'] : [];
        $existingOnlyBuiltDependencies = is_array($existingPnpm['onlyBuiltDependencies'] ?? null)
            ? array_values(array_filter($existingPnpm['onlyBuiltDependencies'], 'is_string'))
            : [];

        return [
            'pnpm' => [
                ...$existingPnpm,
                'onlyBuiltDependencies' => PnpmConfig::getPnpmOnlyBuiltDependencies($scope, $existingOnlyBuiltDependencies),
            ],
        ];
    }

    /** @param Scope $scope */
    public static function createPackageJSON(array $scope): void
    {
        $pkgJSONPath = $scope['rootPath'] . '/package.json';

        $existingPkg = self::readJson($pkgJSONPath);

        $pkg = [
            'name' => self::kebabCase($scope['name']),
            'private' => true,
            'version' => '0.1.0',
            'description' => 'A Strapi application',
            'devDependencies' => $scope['devDependencies'],
            'dependencies' => $scope['dependencies'],
            'strapi' => [
                ...$scope['packageJsonStrapi'],
                'uuid' => $scope['uuid'],
                'installId' => $scope['installId'],
            ],
            'engines' => Engines::ENGINES,
            ...self::getPnpmPackageJsonConfig($scope, $existingPkg),
        ];

        self::writeJson($pkgJSONPath, self::emptyMapsAsObjects(self::sortPackageJson(self::mergePackageJson($existingPkg, $pkg))));
    }

    /**
     * `{}` stays `{}` in JSON (PHP encodes an empty array as `[]`).
     *
     * @param array<string, mixed> $pkg
     * @return array<string, mixed>
     */
    public static function emptyMapsAsObjects(array $pkg): array
    {
        foreach ([...self::SORTED_OBJECTS, 'scripts', 'strapi'] as $key) {
            if (($pkg[$key] ?? null) === []) {
                $pkg[$key] = new \stdClass();
            }
        }

        return $pkg;
    }

    /** lodash `kebabCase`. */
    public static function kebabCase(string $value): string
    {
        preg_match_all('/\p{Lu}+(?!\p{Ll})|\p{Lu}?\p{Ll}+|\p{Lu}|\p{N}+/u', $value, $m);

        return implode('-', array_map(static fn (string $word): string => mb_strtolower($word), $m[0]));
    }

    /**
     * `sort-package-json`: well-known keys in their conventional order, unknown keys after them in
     * their original order, private (`_`-prefixed) keys last; dependency maps and `engines` sorted
     * by name, `scripts` sorted by name with `preX` / `postX` kept around `X`.
     *
     * @param array<string, mixed> $pkg
     * @return array<string, mixed>
     */
    public static function sortPackageJson(array $pkg): array
    {
        $sorted = [];
        foreach (self::KEY_ORDER as $key) {
            if (array_key_exists($key, $pkg)) {
                $sorted[$key] = $pkg[$key];
            }
        }
        foreach ($pkg as $key => $value) {
            if (!array_key_exists($key, $sorted) && !str_starts_with((string) $key, '_')) {
                $sorted[$key] = $value;
            }
        }
        foreach ($pkg as $key => $value) {
            if (!array_key_exists($key, $sorted)) {
                $sorted[$key] = $value;
            }
        }

        foreach (self::SORTED_OBJECTS as $key) {
            if (is_array($sorted[$key] ?? null) && !array_is_list($sorted[$key])) {
                ksort($sorted[$key], SORT_STRING);
            }
        }

        if (is_array($sorted['scripts'] ?? null) && !array_is_list($sorted['scripts'])) {
            $sorted['scripts'] = self::sortScripts($sorted['scripts']);
        }

        return $sorted;
    }

    /**
     * @param array<array-key, mixed> $scripts
     * @return array<array-key, mixed>
     */
    private static function sortScripts(array $scripts): array
    {
        $names = array_map('strval', array_keys($scripts));
        $base = static function (string $name) use ($names): string {
            foreach (['pre', 'post'] as $prefix) {
                if (str_starts_with($name, $prefix) && in_array(substr($name, strlen($prefix)), $names, true)) {
                    return substr($name, strlen($prefix));
                }
            }

            return $name;
        };
        $rank = static fn (string $name): int => $base($name) === $name ? 1 : (str_starts_with($name, 'pre') ? 0 : 2);

        usort($names, static fn (string $a, string $b): int => [$base($a), $rank($a)] <=> [$base($b), $rank($b)]);

        $sorted = [];
        foreach ($names as $name) {
            $sorted[$name] = $scripts[$name];
        }

        return $sorted;
    }

    /** @return array<string, mixed> */
    public static function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** `fse.writeJSON(path, data, { spaces: 2 })`. */
    public static function writeJson(string $path, mixed $data): void
    {
        $json = (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // PHP indents with 4 spaces, npm/fs-extra with 2
        $json = (string) preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json);

        file_put_contents($path, $json . "\n");
    }
}
