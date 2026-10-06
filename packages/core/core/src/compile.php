<?php

declare(strict_types=1);

namespace Strapi\Core;

use Strapi\Core\Utils\ResolveWorkingDirs;

/**
 * Port of packages/core/core/src/compile.ts (`compileStrapi`). PHP has no TypeScript compile
 * step: this only resolves the working directories.
 */
final class Compile
{
    /**
     * @param array{appDir?: string|null, distDir?: string|null} $options
     * @return array{appDir: string, distDir: string}
     */
    public static function compileStrapi(array $options = []): array
    {
        return ResolveWorkingDirs::resolveWorkingDirectories($options);
    }
}
