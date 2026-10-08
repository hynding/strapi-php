<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Filters\Operators;

use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;

/** Port of server/src/services/builders/filters/operators/lt.ts */
final class Lt extends Operator
{
    public function __construct()
    {
        parent::__construct('lt', '$lt');
    }

    public function add(OutputDefinitionBlock $t, string $type): void
    {
        $t->field('lt', ['type' => $type]);
    }
}
