<?php

declare(strict_types=1);

namespace Strapi\Openapi\Assemblers\Document\Path\PathItem\Operation;

use Strapi\Openapi\Assemblers\Assembler;
use Strapi\Openapi\Context\Context;
use Strapi\Openapi\Utils\Debug;

/** Port of packages/core/openapi/src/assemblers/document/path/path-item/operation/tags.ts. */
final class OperationTagsAssembler implements Assembler\Operation
{
    public function assemble(Context $context, array $route): void
    {
        $debug = Debug::createDebugger('assembler:tags');

        $method = $route['method'] ?? null;
        $path = $route['path'] ?? null;
        $info = is_array($route['info'] ?? null) ? $route['info'] : [];
        $apiName = $info['apiName'] ?? null;
        $pluginName = $info['pluginName'] ?? null;

        $debug('assembling tags for %o %o...', $method, $path);

        $tags = [];

        if (is_string($apiName) && $apiName !== '') {
            $tags[] = $apiName;
        }

        if (is_string($pluginName) && $pluginName !== '') {
            $tags[] = $pluginName;
        }

        $debug('assembled %o tags for %o %o: %o', count($tags), $method, $path, $tags);

        $context->output->data['tags'] = $tags;
    }
}
