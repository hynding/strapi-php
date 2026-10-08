<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Internals\Types;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\InputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\InputObjectTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Internals\Internals;
use Strapi\Plugin\Graphql\Services\Utils\Utils;

/** Port of server/src/services/internals/types/filters.ts */
final class Filters
{
    /**
     * Build a map of filters type for every GraphQL scalars
     *
     * @return array<string, InputObjectTypeDef>
     */
    private static function buildScalarFilters(Strapi $strapi): array
    {
        $utils = $strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);
        $internals = $strapi->plugin('graphql')->service('internals');
        \assert($internals instanceof Internals);

        $acc = [];
        foreach ($internals->helpers->getEnabledScalars() as $type) {
            $operators = $utils->mappers->graphqlScalarToOperators($type);
            $typeName = $utils->naming->getScalarFilterInputTypeName($type);

            if ($operators === null || count($operators) === 0) {
                continue;
            }

            $acc[$typeName] = Nexus::inputObjectType([
                'name' => $typeName,

                'definition' => static function (InputDefinitionBlock $t) use ($operators, $type): void {
                    foreach ($operators as $operator) {
                        $operator->add($t, $type);
                    }
                },
            ]);
        }

        return $acc;
    }

    /** @return array{scalars: array<string, InputObjectTypeDef>} */
    public static function create(Strapi $strapi): array
    {
        return [
            'scalars' => self::buildScalarFilters($strapi),
        ];
    }
}
