<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Utils;

use Strapi\CreateStrapiApp\Types;

/**
 * No upstream file: the PHP half of `createPackageJSON()`. Writes the project's `composer.json`,
 * merging the template's (scripts, autoload...) with the Strapi packages to require.
 *
 * Versions follow VERSIONING.md: a stable `5.56.0` (or `5.56.0.1`) is required as `^5.56`; a
 * pre-release `5.57.0-beta.1` is required exactly and the project gets
 * `"minimum-stability": "beta", "prefer-stable": true` (rule 3: a `@beta` flag on the requirement
 * would not reach the stability of the package's own dependencies).
 *
 * @phpstan-import-type Scope from Types
 */
final class ComposerJson
{
    /**
     * What a project requires: strapi-php is published as one Composer package that `replace`s
     * every `strapi/*` package (the CLI, the admin API, the internal plugins, the providers), the
     * counterpart of upstream's `@strapi/strapi`.
     */
    public const STRAPI_PACKAGES = ['hynding/strapi-php'];

    private const KEY_ORDER = [
        'name', 'description', 'type', 'keywords', 'homepage', 'license', 'authors', 'version',
        'require', 'require-dev', 'conflict', 'replace', 'provide', 'suggest', 'autoload',
        'autoload-dev', 'repositories', 'scripts', 'scripts-descriptions', 'extra', 'config',
        'minimum-stability', 'prefer-stable',
    ];

    /** The Composer constraint a project uses for strapi/* packages of `$version`. */
    public static function constraint(string $version): string
    {
        if (self::isPreRelease($version)) {
            return $version;
        }

        return preg_match('/^v?(\d+)\.(\d+)/', $version, $m) === 1 ? "^{$m[1]}.{$m[2]}" : $version;
    }

    public static function isPreRelease(string $version): bool
    {
        return preg_match('/-(alpha|beta|rc|dev)/i', $version) === 1 || str_starts_with($version, 'dev-');
    }

    /**
     * @return array<string, string>
     */
    public static function strapiDependencies(string $version): array
    {
        $constraint = self::constraint($version);

        return ['php' => Engines::PHP, ...array_fill_keys(self::STRAPI_PACKAGES, $constraint)];
    }

    /** @param Scope $scope */
    public static function createComposerJSON(array $scope): void
    {
        $path = $scope['rootPath'] . '/composer.json';
        $existing = PackageJson::readJson($path);

        $composer = [
            'description' => 'A Strapi application',
            'type' => 'project',
            'require' => $scope['composerDependencies'],
            'config' => [
                'sort-packages' => true,
                // `composer start` / `composer develop` run until stopped
                'process-timeout' => 0,
                'allow-plugins' => false,
            ],
        ];

        if (self::isPreRelease($scope['strapiVersion'])) {
            $composer['minimum-stability'] = 'beta';
            $composer['prefer-stable'] = true;
        }

        $merged = PackageJson::mergeWith($existing, $composer);
        if (is_array($merged['require'] ?? null)) {
            $merged['require'] = self::sortRequire($merged['require']);
        }

        self::writeJson($path, self::sortComposerJson($merged));
    }

    /**
     * Composer's `sort-packages` order: php, then extensions, then packages.
     *
     * @param array<array-key, mixed> $require
     * @return array<array-key, mixed>
     */
    public static function sortRequire(array $require): array
    {
        uksort($require, static function (int|string $a, int|string $b): int {
            $rank = static fn (string $name): int => match (true) {
                $name === 'php' => 0,
                str_starts_with($name, 'ext-') => 1,
                str_starts_with($name, 'lib-') => 2,
                default => 3,
            };

            return [$rank((string) $a), (string) $a] <=> [$rank((string) $b), (string) $b];
        });

        return $require;
    }

    /**
     * @param array<string, mixed> $composer
     * @return array<string, mixed>
     */
    private static function sortComposerJson(array $composer): array
    {
        $sorted = [];
        foreach (self::KEY_ORDER as $key) {
            if (array_key_exists($key, $composer)) {
                $sorted[$key] = $composer[$key];
            }
        }

        return $sorted + $composer;
    }

    public static function writeJson(string $path, mixed $data): void
    {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
}
