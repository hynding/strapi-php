<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Filters\Operators;

use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;

/** Port of server/src/services/builders/filters/operators/not-null.ts */
final class NotNull extends Operator
{
    public function __construct()
    {
        parent::__construct('notNull', '$notNull');
    }

    public function add(OutputDefinitionBlock $t, string $type): void
    {
        $t->boolean('notNull');
    }
}
