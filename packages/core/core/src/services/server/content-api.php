<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Server;

use Strapi\Core\Strapi;

/** Port of packages/core/core/src/services/server/content-api.ts. */
final class ContentApi extends Api
{
    public static function createContentAPI(Strapi $strapi): self
    {
        return new self($strapi, ['prefix' => (string) $strapi->config()->get('api.rest.prefix', '/api'), 'type' => 'content-api']);
    }
}
