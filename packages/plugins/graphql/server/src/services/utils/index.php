<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Utils;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Services\Utils\Mappers\Mappers;

/**
 * Port of server/src/services/utils/index.ts: the `utils` service
 * (`{ playground, naming, attributes, mappers }`). `playground` is also callable as a method,
 * which is how `strapi::security` reads it.
 */
final class Utils
{
    public readonly Playground $playground;

    public readonly Naming $naming;

    public readonly Attributes $attributes;

    public readonly Mappers $mappers;

    public function __construct(Strapi $strapi)
    {
        $this->playground = new Playground();
        $this->naming = new Naming($strapi);
        $this->attributes = new Attributes();
        $this->mappers = new Mappers($strapi);
    }

    public function playground(): Playground
    {
        return $this->playground;
    }
}
