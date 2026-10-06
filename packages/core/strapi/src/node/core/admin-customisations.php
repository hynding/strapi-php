<?php

declare(strict_types=1);

namespace Strapi\Cli\Node\Core;

/**
 * Port of packages/core/strapi/src/node/core/admin-customisations.ts (`loadUserAppFile`):
 * the user's `src/admin/app.{js,mjs,ts,jsx,tsx}` admin customisations.
 */
final class AdminCustomisations
{
    private const ADMIN_APP_FILES = ['app.js', 'app.mjs', 'app.ts', 'app.jsx', 'app.tsx'];

    /** @return array{path: string, modulePath: string}|null */
    public static function loadUserAppFile(string $appDir, string $runtimeDir): ?array
    {
        foreach (self::ADMIN_APP_FILES as $file) {
            $filePath = $appDir . '/src/admin/' . $file;

            if (Files::pathExists($filePath)) {
                return [
                    'path' => $filePath,
                    'modulePath' => Files::convertSystemPathToModulePath(Files::relative($runtimeDir, $filePath)),
                ];
            }
        }

        return null;
    }
}
