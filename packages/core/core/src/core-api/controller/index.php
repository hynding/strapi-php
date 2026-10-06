<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Controller;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;

/** Port of core-api/controller/index.ts (`createController`). */
final class Controller
{
    public static function createController(Strapi $strapi, Schema $contentType): Base
    {
        if (ContentTypes::isSingleType($contentType)) {
            return new SingleType($strapi, $contentType);
        }

        return new CollectionType($strapi, $contentType);
    }
}
