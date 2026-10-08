<?php

declare(strict_types=1);

namespace Strapi\Admin\Controllers;

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

/** Port of server/src/controllers/content-api.ts (`admin::content-api`). */
final class ContentApi
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function getPermissions(Context $ctx): mixed
    {
        $actionsMap = $this->strapi->contentAPI()->permissions->getActionsMap();
        $ctx->send(['data' => $actionsMap]);

        return null;
    }

    public function getRoutes(Context $ctx): mixed
    {
        $routesMap = $this->strapi->contentAPI()->getRoutesMap();
        $ctx->send(['data' => $routesMap]);

        return null;
    }
}
