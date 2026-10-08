<?php

declare(strict_types=1);

namespace Strapi\Upload\Controllers\Utils;

/** Port of server/src/controllers/utils/folders.ts. */
final class Folders
{
    /**
     * Only the materialized `path` is compared, so any row carrying one can be passed.
     *
     * @param array{path: string} $folderOrChild
     * @param array{path: string} $folder
     */
    public static function isFolderOrChild(array $folderOrChild, array $folder): bool
    {
        return $folderOrChild['path'] === $folder['path'] || str_starts_with($folderOrChild['path'], "{$folder['path']}/");
    }
}
