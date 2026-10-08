<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Utils\Mappers;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Services\Builders\Builders;
use Strapi\Plugin\Graphql\Services\Builders\Filters\Operators\Operator;
use Strapi\Plugin\Graphql\Services\Constants;

/** Port of server/src/services/utils/mappers/graphql-scalar-to-operators.ts */
final class GraphqlScalarToOperators
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return list<Operator>|null */
    public function graphqlScalarToOperators(string $graphqlScalar): ?array
    {
        $builders = $this->strapi->plugin('graphql')->service('builders');
        \assert($builders instanceof Builders);
        $operators = $builders->filters->operators;

        $operatorNames = Constants::GRAPHQL_SCALAR_OPERATORS[$graphqlScalar] ?? null;
        if ($operatorNames === null) {
            return null;
        }

        return array_values(array_map(static fn (string $operatorName): Operator => $operators[$operatorName], $operatorNames));
    }
}
