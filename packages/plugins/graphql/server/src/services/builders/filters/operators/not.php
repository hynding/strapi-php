<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Filters\Operators;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Services\Utils\Utils;

/** Port of server/src/services/builders/filters/operators/not.ts */
final class Not extends Operator
{
    public function __construct(private readonly Strapi $strapi)
    {
        parent::__construct('not', '$not');
    }

    public function add(OutputDefinitionBlock $t, string $type): void
    {
        $utils = $this->strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);

        if ($utils->attributes->isGraphQLScalar(['type' => $type])) {
            $t->field('not', ['type' => $utils->naming->getScalarFilterInputTypeName($type)]);
        } else {
            $t->field('not', ['type' => $type]);
        }
    }
}
