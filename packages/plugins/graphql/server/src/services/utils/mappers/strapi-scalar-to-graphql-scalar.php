<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Utils\Mappers;

use Strapi\Plugin\Graphql\Services\Constants;
use Strapi\Utils\Errors\ApplicationError;

/** Port of server/src/services/utils/mappers/strapi-scalar-to-graphql-scalar.ts */
final class StrapiScalarToGraphqlScalar
{
    public function __construct()
    {
        $missingStrapiScalars = array_diff(Constants::STRAPI_SCALARS, array_keys(Constants::SCALARS_ASSOCIATIONS));

        if (count($missingStrapiScalars) > 0) {
            throw new ApplicationError('Some Strapi scalars are not handled in the GraphQL scalars mapper');
        }
    }

    /**
     * Used to transform a Strapi scalar type into its GraphQL equivalent
     */
    public function strapiScalarToGraphQLScalar(mixed $strapiScalar): ?string
    {
        return is_string($strapiScalar) ? (Constants::SCALARS_ASSOCIATIONS[$strapiScalar] ?? null) : null;
    }
}
