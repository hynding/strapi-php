<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Filters\Operators;

use Strapi\Core\Strapi;

/** Port of server/src/services/builders/filters/operators/index.ts */
final class Operators
{
    /**
     * Instantiate every operator with the Strapi instance
     *
     * @return array<string, Operator>
     */
    public static function create(Strapi $strapi): array
    {
        return [
            'and' => new AndOperator(),
            'or' => new OrOperator(),
            'not' => new Not($strapi),
            'eq' => new Eq(),
            'eqi' => new Eqi(),
            'ne' => new Ne(),
            'nei' => new Nei(),
            'startsWith' => new StartsWith(),
            'endsWith' => new EndsWith(),
            'contains' => new Contains(),
            'notContains' => new NotContains(),
            'containsi' => new Containsi(),
            'notContainsi' => new NotContainsi(),
            'gt' => new Gt(),
            'gte' => new Gte(),
            'lt' => new Lt(),
            'lte' => new Lte(),
            'null' => new NullOperator(),
            'notNull' => new NotNull(),
            'in' => new In(),
            'notIn' => new NotIn(),
            'between' => new Between(),
        ];
    }
}
