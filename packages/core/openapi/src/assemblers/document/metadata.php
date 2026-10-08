<?php

declare(strict_types=1);

namespace Strapi\Openapi\Assemblers\Document;

use Strapi\Openapi\Assemblers\Assembler;
use Strapi\Openapi\Context\Context;
use Strapi\Openapi\Utils\Debug;

/** Port of packages/core/openapi/src/assemblers/document/metadata.ts. */
final class DocumentMetadataAssembler implements Assembler\Document
{
    public function assemble(Context $context): void
    {
        $debug = Debug::createDebugger('assembler:metadata');

        $strapiVersion = $context->strapi->config()->get('info.strapi');

        $debug("assembling document's metadata for %O...", ['strapiVersion' => $strapiVersion]);

        $metadata = [
            'openapi' => '3.1.0',
            'x-powered-by' => 'strapi',
            'x-strapi-version' => $strapiVersion,
        ];

        $debug("document's metadata assembled: %O", $metadata);

        // Object.assign(context.output.data, metadataObject)
        foreach ($metadata as $key => $value) {
            $context->output->data[$key] = $value;
        }
    }
}
