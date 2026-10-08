<?php

declare(strict_types=1);

namespace Strapi\Openapi\Assemblers\Document\Path;

use Strapi\Openapi\Assemblers\Assembler;
use Strapi\Openapi\Assemblers\Document\Path\PathItem\PathItemAssembler;
use Strapi\Openapi\Assemblers\Document\Path\PathItem\PathItemAssemblerFactory;
use Strapi\Openapi\Context\Factories\PathItemContextFactory;

/** Port of packages/core/openapi/src/assemblers/document/path/factory.ts. */
final class PathAssemblerFactory
{
    /** @return list<Assembler\Path> */
    public function createAll(): array
    {
        return [$this->createPathItemAssembler()];
    }

    private function createPathItemAssembler(
        PathItemAssemblerFactory $assemblerFactory = new PathItemAssemblerFactory(),
        PathItemContextFactory $contextFactory = new PathItemContextFactory(),
    ): PathItemAssembler {
        $assemblers = $assemblerFactory->createAll();

        return new PathItemAssembler($assemblers, $contextFactory);
    }
}
