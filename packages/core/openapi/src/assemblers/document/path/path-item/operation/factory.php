<?php

declare(strict_types=1);

namespace Strapi\Openapi\Assemblers\Document\Path\PathItem\Operation;

use Strapi\Openapi\Assemblers\Assembler;

/** Port of packages/core/openapi/src/assemblers/document/path/path-item/operation/factory.ts. */
final class OperationAssemblerFactory
{
    /** @return list<Assembler\Operation> */
    public function createAll(): array
    {
        return [
            $this->createOperationIDAssembler(),
            $this->createParametersAssembler(),
            $this->createResponsesAssembler(),
            $this->createTagsAssembler(),
            $this->createBodyAssembler(),
        ];
    }

    private function createOperationIDAssembler(): OperationIDAssembler
    {
        return new OperationIDAssembler();
    }

    private function createParametersAssembler(): OperationParametersAssembler
    {
        return new OperationParametersAssembler();
    }

    private function createResponsesAssembler(): OperationResponsesAssembler
    {
        return new OperationResponsesAssembler();
    }

    private function createTagsAssembler(): OperationTagsAssembler
    {
        return new OperationTagsAssembler();
    }

    private function createBodyAssembler(): BodyAssembler
    {
        return new BodyAssembler();
    }
}
