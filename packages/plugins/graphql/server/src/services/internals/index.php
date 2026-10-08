<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Internals;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ScalarTypeDef;
use Strapi\Plugin\Graphql\Services\Internals\Args\Args;
use Strapi\Plugin\Graphql\Services\Internals\Helpers\Helpers;
use Strapi\Plugin\Graphql\Services\Internals\Scalars\Scalars;
use Strapi\Plugin\Graphql\Services\Internals\Types\Types;

/** Port of server/src/services/internals/index.ts: the `internals` service */
final class Internals
{
    public readonly Args $args;

    /** @var array<string, ScalarTypeDef> */
    public readonly array $scalars;

    public readonly Helpers $helpers;

    public function __construct(private readonly Strapi $strapi)
    {
        $this->args = new Args();
        $this->scalars = Scalars::create();
        $this->helpers = new Helpers();
    }

    /** @return array<string, array<string, mixed>> */
    public function buildInternalTypes(): array
    {
        return Types::buildInternalTypes($this->strapi);
    }
}
