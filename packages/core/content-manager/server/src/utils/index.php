<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Utils;

use Strapi\ContentManager\Services;
use Strapi\Core\Strapi;

/**
 * Port of server/src/utils/index.ts: `getService(name)` = `strapi.plugin('content-manager').service(name)`.
 * The conditional return type is for static analysis; at runtime any registered (or replaced)
 * service object is returned, so unit tests can register stubs.
 */
final class Utils
{
    /**
     * @return ($name is 'components' ? Services\Components : ($name is 'content-types' ? Services\ContentTypes : ($name is 'data-mapper' ? Services\DataMapper : ($name is 'document-manager' ? Services\DocumentManager : ($name is 'document-metadata' ? Services\DocumentMetadata : ($name is 'field-sizes' ? Services\FieldSizes : ($name is 'metrics' ? Services\Metrics : ($name is 'permission-checker' ? Services\PermissionChecker : ($name is 'permission' ? Services\Permission : ($name is 'populate-builder' ? Services\PopulateBuilder : ($name is 'uid' ? Services\Uid : ($name is 'content-structure' ? Services\ContentStructure : object))))))))))))
     */
    public static function getService(Strapi $strapi, string $name): object
    {
        return $strapi->service("plugin::content-manager.{$name}");
    }
}
