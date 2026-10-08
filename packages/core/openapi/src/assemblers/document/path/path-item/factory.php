<?php

declare(strict_types=1);

namespace Strapi\Openapi\Assemblers\Document\Path\PathItem;

use Strapi\Openapi\Assemblers\Assembler;
use Strapi\Openapi\Assemblers\Document\Path\PathItem\Operation\OperationAssembler;
use Strapi\Openapi\Assemblers\Document\Path\PathItem\Operation\OperationAssemblerFactory;
use Strapi\Openapi\Context\Factories\OperationContextFactory;

/** Port of packages/core/openapi/src/assemblers/document/path/path-item/factory.ts. */
final class PathItemAssemblerFactory
{
    /** @return list<Assembler\PathItem> */
    public function createAll(): array
    {
        return [$this->createOperationAssembler()];
    }

    private function createOperationAssembler(
        OperationAssemblerFactory $assemblerFactory = new OperationAssemblerFactory(),
        OperationContextFactory $contextFactory = new OperationContextFactory(),
    ): OperationAssembler {
        $assemblers = $assemblerFactory->createAll();

        return new OperationAssembler($assemblers, $contextFactory);
    }
}
