<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Server;

use Strapi\Core\Strapi;

/** Port of packages/core/core/src/services/server/admin-api.ts. */
final class AdminApi extends Api
{
    public static function createAdminAPI(Strapi $strapi): self
    {
        return new self($strapi, ['prefix' => '', 'type' => 'admin']);
    }
}
