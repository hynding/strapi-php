<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Internals\Args;

use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ArgDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;

/**
 * Port of server/src/services/internals/args/has-published-version.ts.
 *
 * @deprecated Use `publicationFilter` (`NEVER_PUBLISHED` / `HAS_PUBLISHED_VERSION`, etc.) instead.
 * Kept for GraphQL backward compatibility with existing clients.
 */
final class HasPublishedVersion
{
    public static function create(): ArgDef
    {
        return Nexus::booleanArg();
    }
}
