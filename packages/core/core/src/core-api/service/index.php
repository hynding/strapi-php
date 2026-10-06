<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Service;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;

/** Port of core-api/service/index.ts (`createService`). */
final class Service
{
    public static function createService(Strapi $strapi, Schema $contentType): CoreService
    {
        if (ContentTypes::isSingleType($contentType)) {
            return new SingleType($strapi, $contentType);
        }

        return new CollectionType($strapi, $contentType);
    }
}
