<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Filters\Operators;

use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;

/** Port of server/src/services/builders/filters/operators/or.ts */
final class OrOperator extends Operator
{
    public function __construct()
    {
        parent::__construct('or', '$or');
    }

    public function add(OutputDefinitionBlock $t, string $type): void
    {
        $t->field('or', ['type' => Nexus::list($type)]);
    }
}
