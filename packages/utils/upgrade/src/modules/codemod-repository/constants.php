<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\CodemodRepository;

/** Port of packages/utils/upgrade/src/modules/codemod-repository/constants.ts. */
final class Constants
{
    public static function internalCodemodsDirectory(): string
    {
        // upgrade/src/modules/codemod-repository → upgrade/resources/codemods
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'codemods';
    }
}
