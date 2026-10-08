<?php

declare(strict_types=1);

namespace Strapi\Openapi\Assemblers\Document;

use Strapi\Openapi\Assemblers\Assembler;
use Strapi\Openapi\Assemblers\Document\Path\DocumentPathsAssembler;
use Strapi\Openapi\Assemblers\Document\Path\PathAssemblerFactory;
use Strapi\Openapi\Context\Factories\PathContextFactory;

/** Port of packages/core/openapi/src/assemblers/document/factory.ts. */
final class DocumentAssemblerFactory
{
    /** @return list<Assembler\Document> */
    public function createAll(): array
    {
        return [
            $this->createMetadataAssembler(),
            $this->createInfoAssembler(),
            $this->createServerAssembler(),
            $this->createSecurityAssembler(),
            $this->createPathsAssembler(),
        ];
    }

    private function createInfoAssembler(): DocumentInfoAssembler
    {
        return new DocumentInfoAssembler();
    }

    private function createMetadataAssembler(): DocumentMetadataAssembler
    {
        return new DocumentMetadataAssembler();
    }

    private function createSecurityAssembler(): DocumentSecurityAssembler
    {
        return new DocumentSecurityAssembler();
    }

    private function createServerAssembler(): DocumentServerAssembler
    {
        return new DocumentServerAssembler();
    }

    private function createPathsAssembler(
        PathAssemblerFactory $assemblerFactory = new PathAssemblerFactory(),
        PathContextFactory $contextFactory = new PathContextFactory(),
    ): DocumentPathsAssembler {
        $assemblers = $assemblerFactory->createAll();

        return new DocumentPathsAssembler($assemblers, $contextFactory);
    }
}
