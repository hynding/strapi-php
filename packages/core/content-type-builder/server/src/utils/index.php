<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Utils;

use Strapi\ContentTypeBuilder\Services;
use Strapi\Core\Core;
use Strapi\Core\Strapi;

/**
 * Port of server/src/utils/index.ts: `getService(name)` =
 * `strapi.plugin('content-type-builder').service(name)`. Upstream reads the global `strapi`;
 * here the instance is passed, or {@see Core::instance()} (upstream's `global.strapi`) is used.
 */
final class Utils
{
    /**
     * @return ($name is 'content-types' ? Services\ContentTypes : ($name is 'components' ? Services\Components : ($name is 'component-categories' ? Services\ComponentCategories : ($name is 'builder' ? Services\Builder : ($name is 'api-handler' ? Services\ApiHandler : ($name is 'schema' ? Services\Schema : ($name is 'content-structure' ? Services\ContentStructure : object)))))))
     */
    public static function getService(string $name, ?Strapi $strapi = null): object
    {
        $strapi ??= Core::instance() ?? throw new \RuntimeException('Strapi is not initialized');

        // strapi.plugin('content-type-builder').service(name) is the service `plugin::content-type-builder.<name>`
        return $strapi->service("plugin::content-type-builder.{$name}");
    }
}
