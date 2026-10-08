<?php

declare(strict_types=1);

namespace Strapi\Openapi\Assemblers\Document;

use Strapi\Openapi\Assemblers\Assembler;
use Strapi\Openapi\Context\Context;
use Strapi\Openapi\Utils\Debug;

/** Port of packages/core/openapi/src/assemblers/document/info.ts. */
final class DocumentInfoAssembler implements Assembler\Document
{
    public function assemble(Context $context): void
    {
        $debug = Debug::createDebugger('assembler:info');

        $infoConfig = $context->strapi->config()->get('info');
        $name = (string) (is_array($infoConfig) ? ($infoConfig['name'] ?? '') : '');
        $version = (string) (is_array($infoConfig) ? ($infoConfig['version'] ?? '') : '');

        $debug("assembling document's info for %O...", ['name' => $name, 'version' => $version]);

        $info = [
            'title' => $this->title($name),
            'description' => $this->description($name, $version),
            'version' => $version,
        ];

        $debug("document's info assembled: %O", $info);

        $context->output->data['info'] = $info;
    }

    private function title(string $name): string
    {
        return $name;
    }

    private function description(string $name, string $version): string
    {
        return "API documentation for {$name} v{$version}";
    }
}
