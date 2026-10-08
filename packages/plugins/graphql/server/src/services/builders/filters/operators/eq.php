<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Filters\Operators;

use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Services\Constants;
use Strapi\Utils\Errors\ValidationError;

/** Port of server/src/services/builders/filters/operators/eq.ts */
final class Eq extends Operator
{
    public function __construct()
    {
        parent::__construct('eq', '$eq');
    }

    public function add(OutputDefinitionBlock $t, string $type): void
    {
        if (!in_array($type, Constants::GRAPHQL_SCALARS, true)) {
            throw new ValidationError("Can't use \"eq\" operator. \"{$type}\" is not a valid scalar");
        }

        $t->field('eq', ['type' => $type]);
    }
}
