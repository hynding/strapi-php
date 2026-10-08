<?php

declare(strict_types=1);

namespace Strapi\Openapi\PostProcessor;

use Strapi\Openapi\Context\Context;

/** Port of packages/core/openapi/src/post-processor/types.ts (`PostProcessor`). */
interface PostProcessor
{
    public function postProcess(Context $context): void;
}
