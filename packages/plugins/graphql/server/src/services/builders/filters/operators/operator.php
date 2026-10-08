<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Filters\Operators;

use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;

/**
 * The shape of upstream's operator objects (`{ fieldName, strapiOperator, add(t, type) }`);
 * PHP-port addition shared by the operator files.
 */
abstract class Operator
{
    public function __construct(public readonly string $fieldName, public readonly string $strapiOperator)
    {
    }

    abstract public function add(OutputDefinitionBlock $t, string $type): void;
}
