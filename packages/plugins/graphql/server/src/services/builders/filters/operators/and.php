<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Filters\Operators;

use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;

/** Port of server/src/services/builders/filters/operators/and.ts */
final class AndOperator extends Operator
{
    public function __construct()
    {
        parent::__construct('and', '$and');
    }

    public function add(OutputDefinitionBlock $t, string $type): void
    {
        $t->field('and', ['type' => Nexus::list($type)]);
    }
}
