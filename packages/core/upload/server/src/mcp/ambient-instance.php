<?php

declare(strict_types=1);

namespace Strapi\Upload\Mcp;

use Strapi\Core\Strapi;
use Strapi\Upload\Services\Folder;
use Strapi\Upload\Utils\Utils as UploadUtils;

/**
 * Port of server/src/mcp/ambient-instance.ts.
 *
 * Upstream's `folder` and `file` services close over the ambient `global.strapi`, so MCP handlers
 * assert their instance is that one. In the PHP port every service is constructed with the
 * instance it belongs to — there is no ambient instance to diverge from — so the assertion always
 * holds; it is kept so the handlers read as upstream's.
 */
final class AmbientInstance
{
    public static function assertAmbientInstance(Strapi $strapi): void
    {
        // services are bound to the instance that built them: nothing can diverge
    }

    /** Resolves the `folder` service for an MCP handler. */
    public static function getFolderService(Strapi $strapi): Folder
    {
        return UploadUtils::getService('folder', $strapi);
    }
}
