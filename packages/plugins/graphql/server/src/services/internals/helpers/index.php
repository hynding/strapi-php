<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Internals\Helpers;

/** Port of server/src/services/internals/helpers/index.ts */
final class Helpers
{
    /** @return list<string> */
    public function getEnabledScalars(): array
    {
        return (new GetEnabledScalars())();
    }
}
