<?php

declare(strict_types=1);

namespace Strapi\Upload\Controllers\Validation\Admin;

use Strapi\Core\Strapi;
use Strapi\Upload\Utils\Utils as UploadUtils;

/** Port of server/src/controllers/validation/admin/utils.ts. */
final class Utils
{
    public static function folderExists(Strapi $strapi, mixed $folderId): bool
    {
        if ($folderId === null || $folderId instanceof \Strapi\Utils\Yup\Undefined) {
            return true;
        }

        return UploadUtils::getService('folder', $strapi)->exists(['id' => $folderId]);
    }
}
