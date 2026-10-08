<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Filters\Operators;

use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;

/** Port of server/src/services/builders/filters/operators/starts-with.ts */
final class StartsWith extends Operator
{
    public function __construct()
    {
        parent::__construct('startsWith', '$startsWith');
    }

    public function add(OutputDefinitionBlock $t, string $type): void
    {
        $t->field('startsWith', ['type' => $type]);
    }
}
