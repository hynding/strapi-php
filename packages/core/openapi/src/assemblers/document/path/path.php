<?php

declare(strict_types=1);

namespace Strapi\Openapi\Assemblers\Document\Path;

use Strapi\Openapi\Assemblers\Assembler;
use Strapi\Openapi\Context\Context;
use Strapi\Openapi\Context\Factories\PathContextFactory;
use Strapi\Openapi\Utils\Debug;

/** Port of packages/core/openapi/src/assemblers/document/path/path.ts. */
final class DocumentPathsAssembler implements Assembler\Document
{
    /** @param list<Assembler\Path> $assemblers */
    public function __construct(
        private readonly array $assemblers,
        private readonly PathContextFactory $contextFactory,
    ) {
    }

    public function assemble(Context $context): void
    {
        $debug = Debug::createDebugger('assembler:paths');

        $debug("assembling document's paths for %O routes...", count($context->routes));

        $pathContext = $this->contextFactory->create([
            'strapi' => $context->strapi,
            'routes' => $context->routes,
            'timer' => $context->timer,
            'registries' => $context->registries,
        ]);

        foreach ($this->assemblers as $assembler) {
            $assembler->assemble($pathContext);
        }

        $pathsObject = $pathContext->output->data;
        $nbUniquePaths = count($pathsObject);

        $debug("document's paths assembled, added %O unique paths", $nbUniquePaths);

        $context->output->data['paths'] = $pathsObject === [] ? new \stdClass() : $pathsObject;
    }
}
