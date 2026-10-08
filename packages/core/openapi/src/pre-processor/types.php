<?php

declare(strict_types=1);

namespace Strapi\Openapi\PreProcessor;

use Strapi\Openapi\Context\Context;

/** Port of packages/core/openapi/src/pre-processor/types.ts (`PreProcessor`). */
interface PreProcessor
{
    public function preProcess(Context $context): void;
}
