<?php

declare(strict_types=1);

use Strapi\Upgrade\Modules\Json\JSONTransformAPI;

/**
 * Port of resources/codemods/5.0.0/dependency-remove-strapi-plugin-i18n.json.ts.
 *
 * Specifically targets the root package.json and removes the @strapi/plugin-i18n dependency.
 *
 * Why? The i18n plugin is now a hard dependency of @strapi/strapi and isn't needed in the package.json anymore.
 *
 * @param array{path: string, json: array<string, mixed>} $file
 * @param array{cwd: string, json: Closure(array<string, mixed>): JSONTransformAPI} $params
 */
return static function (array $file, array $params): array {
    $depPath = 'dependencies["@strapi/plugin-i18n"]';

    $rootPackageJsonPath = $params['cwd'] . DIRECTORY_SEPARATOR . 'package.json';

    if ($file['path'] !== $rootPackageJsonPath) {
        return $file['json'];
    }

    $j = ($params['json'])($file['json']);

    if ($j->has($depPath)) {
        $j->remove($depPath);
    }

    return $j->root();
};
