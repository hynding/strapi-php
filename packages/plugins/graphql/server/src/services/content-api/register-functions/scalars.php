<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\ContentApi\RegisterFunctions;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Services\Constants;
use Strapi\Plugin\Graphql\Services\Internals\Internals;
use Strapi\Plugin\Graphql\Services\TypeRegistry;

/** Port of server/src/services/content-api/register-functions/scalars.ts */
final class Scalars
{
    /** @param array{registry: TypeRegistry, strapi: Strapi} $context */
    public static function registerScalars(array $context): void
    {
        ['registry' => $registry, 'strapi' => $strapi] = $context;

        $internals = $strapi->plugin('graphql')->service('internals');
        \assert($internals instanceof Internals);

        foreach ($internals->scalars as $name => $definition) {
            $registry->register($name, $definition, ['kind' => Constants::KINDS['scalar']]);
        }
    }
}
