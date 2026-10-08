<?php

declare(strict_types=1);

namespace Strapi\Openapi\Registries;

/** Port of packages/core/openapi/src/registries/factory.ts. */
final class RegistriesFactory
{
    public function createAll(): Registries
    {
        return new Registries();
    }
}
