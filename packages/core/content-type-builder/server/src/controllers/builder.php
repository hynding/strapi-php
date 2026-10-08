<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Controllers;

use Strapi\ContentTypeBuilder\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

/** Port of server/src/controllers/builder.ts. */
final class Builder
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function getReservedNames(Context $ctx): mixed
    {
        $ctx->setBody(Utils::getService('builder', $this->strapi)->getReservedNames());

        return null;
    }
}
