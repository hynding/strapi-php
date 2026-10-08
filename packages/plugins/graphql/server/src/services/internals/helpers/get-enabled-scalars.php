<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Internals\Helpers;

use Strapi\Plugin\Graphql\Services\Constants;

/** Port of server/src/services/internals/helpers/get-enabled-scalars.ts */
final class GetEnabledScalars
{
    /** @return list<string> */
    public function __invoke(): array
    {
        $scalars = [];
        foreach (Constants::GRAPHQL_SCALAR_OPERATORS as $scalar => $operators) {
            // To be valid, a GraphQL scalar must have at least one operator enabled
            if (count($operators) > 0) {
                // Only keep the key (the scalar name)
                $scalars[] = $scalar;
            }
        }

        return $scalars;
    }
}
