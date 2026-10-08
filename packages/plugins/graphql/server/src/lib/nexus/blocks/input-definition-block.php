<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\Nexus\Blocks;

/**
 * nexus' `InputDefinitionBlock` (the `t` of an `inputObjectType` / `extendInputType`
 * definition): same API as the output block, field configs take `default` instead of `resolve`.
 */
final class InputDefinitionBlock extends OutputDefinitionBlock
{
}
