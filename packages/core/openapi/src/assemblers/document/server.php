<?php

declare(strict_types=1);

namespace Strapi\Openapi\Assemblers\Document;

use Strapi\Core\Strapi;
use Strapi\Openapi\Assemblers\Assembler;
use Strapi\Openapi\Context\Context;

/**
 * Port of packages/core/openapi/src/assemblers/document/server.ts.
 *
 * @phpstan-type ServerConfig array{url: string, description?: string, variables?: array<string, mixed>}
 */
final class DocumentServerAssembler implements Assembler\Document
{
    private const DEFAULT_SERVER_URL = 'http://localhost:1337';

    public function assemble(Context $context): void
    {
        $context->output->data['servers'] = $this->getServers($context->strapi);
    }

    /**
     * @param Strapi $strapi
     *
     * @return list<mixed>
     */
    private function getServers(object $strapi): array
    {
        $serverConfig = $strapi->config()->get('openapi.servers');
        $serverConfig = is_array($serverConfig) ? $serverConfig : [];

        if (count($serverConfig) > 0) {
            return array_values($serverConfig);
        }

        $serverUrl = $strapi->config()->get('server.url') ?? self::DEFAULT_SERVER_URL;

        return [
            [
                'url' => $serverUrl,
                'description' => 'Default server',
            ],
        ];
    }
}
