<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Internals\Scalars;

use GraphQL\Type\Definition\CustomScalarType;
use Strapi\Plugin\Graphql\Lib\GraphqlScalars\Date as GraphQLDate;

/**
 * Port of server/src/services/internals/scalars/date.ts. Upstream patches graphql-scalars'
 * `GraphQLDate.parseValue` / `parseLiteral` to cast the parsed `Date` back to a `YYYY-MM-DD`
 * string; the PHP `GraphQLDate` already returns that string.
 */
final class Date
{
    public static function type(): CustomScalarType
    {
        return GraphQLDate::type();
    }
}
