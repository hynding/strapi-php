<?php

declare(strict_types=1);

use Strapi\Upgrade\Modules\Json\JSONTransformAPI;
use Strapi\Upgrade\Modules\Version\NodeSemver\Range;

/**
 * Port of resources/codemods/5.0.0/dependency-upgrade-react-and-react-dom.json.ts.
 *
 * Specifically targets the root package.json and updates the react and react-dom dependency version.
 *
 * We first check if the react and react-dom dependency is listed in the package.json. If the dependency is
 * found, we verify its version.
 *
 * If the detected version does not satisfy the new version range, we replace it with the new one.
 *
 * Conversely, if no react or react-dom dependency is listed, we add it with the new version range.
 *
 * @param array{path: string, json: array<string, mixed>} $file
 * @param array{cwd: string, json: Closure(array<string, mixed>): JSONTransformAPI} $params
 */
return static function (array $file, array $params): array {
    $depPaths = ['dependencies.react', 'dependencies["react-dom"]'];
    $depNewVersionRange = '^18.0.0';

    $rootPackageJsonPath = $params['cwd'] . DIRECTORY_SEPARATOR . 'package.json';

    if ($file['path'] !== $rootPackageJsonPath) {
        return $file['json'];
    }

    $j = ($params['json'])($file['json']);

    $allListed = array_reduce($depPaths, static fn (bool $carry, string $path): bool => $carry && $j->has($path), true);

    if ($allListed) {
        $currentVersions = array_map(static fn (string $path): mixed => $j->get($path), $depPaths);

        // If the current version is not a string, then something is wrong, abort
        foreach ($currentVersions as $currentVersion) {
            if (!is_string($currentVersion)) {
                return $j->root();
            }
        }

        // if the current version satisfies the new range, keep it as is and abort
        $currentSatisfiesNew = array_reduce($currentVersions, static fn (bool $carry, string $version): bool => $carry && Range::satisfies($version, $depNewVersionRange), true);
        if ($currentSatisfiesNew) {
            return $j->root();
        }
    }

    // else (or if the dependency is not listed yet), set the new version range
    foreach ($depPaths as $path) {
        $j->set($path, $depNewVersionRange);
    }

    return $j->root();
};
