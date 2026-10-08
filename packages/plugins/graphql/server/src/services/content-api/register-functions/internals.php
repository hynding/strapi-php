<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\ContentApi\RegisterFunctions;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Services\Internals\Internals as InternalsService;
use Strapi\Plugin\Graphql\Services\TypeRegistry;

/** Port of server/src/services/content-api/register-functions/internals.ts */
final class Internals
{
    /** @param array{registry: TypeRegistry, strapi: Strapi} $context */
    public static function registerInternals(array $context): void
    {
        ['registry' => $registry, 'strapi' => $strapi] = $context;

        $internals = $strapi->plugin('graphql')->service('internals');
        \assert($internals instanceof InternalsService);

        $internalTypes = $internals->buildInternalTypes();

        foreach ($internalTypes as $kind => $definitions) {
            $registry->registerMany(self::flatten($definitions), ['kind' => $kind]);
        }
    }

    /**
     * `Object.entries(definitions)` where the entries are `{ Name: definition }` maps (or a
     * definition directly, for `error`): keyed by the definitions' type names.
     *
     * @param array<string, mixed> $definitions
     * @return array<string, mixed>
     */
    private static function flatten(array $definitions): array
    {
        $entries = [];
        foreach ($definitions as $key => $definition) {
            $entries[$key] = $definition;
        }

        return $entries;
    }
}
