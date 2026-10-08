<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Format;

/** Port of server/src/services/format/index.ts: the `format` service (`{ returnTypes }`). */
final class Format
{
    public readonly ReturnTypes $returnTypes;

    public function __construct()
    {
        $this->returnTypes = new ReturnTypes();
    }
}
