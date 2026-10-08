<?php

declare(strict_types=1);

namespace Strapi\Openapi\PostProcessor;

/** Port of packages/core/openapi/src/post-processor/factory.ts. */
final class PostProcessorsFactory
{
    /** @return list<PostProcessor> */
    public function createAll(): array
    {
        return [new ComponentsWriter()];
    }
}
