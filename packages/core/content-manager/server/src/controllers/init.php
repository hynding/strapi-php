<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Controllers;

use Strapi\ContentManager\Utils\Utils;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/** Port of server/src/controllers/init.ts. */
final class Init
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function getInitData(Context $ctx): mixed
    {
        $dataMapper = Utils::getService($this->strapi, 'data-mapper');
        $components = Utils::getService($this->strapi, 'components');
        $fieldSizes = Utils::getService($this->strapi, 'field-sizes');
        $contentTypes = Utils::getService($this->strapi, 'content-types');
        $contentStructureService = Utils::getService($this->strapi, 'content-structure');

        $contentStructure = $contentStructureService->getContentStructure();

        $ctx->setBody([
            'data' => [
                'fieldSizes' => $fieldSizes->getAllFieldSizes(),
                'components' => array_map(static fn (array $component): array => $dataMapper->toDto($component), $components->findAllComponents()),
                'contentTypes' => array_map(static fn (array $contentType): array => $dataMapper->toDto($contentType), $contentTypes->findAllContentTypes()),
                ...($contentStructure !== null ? ['contentStructure' => $contentStructure] : []),
            ],
        ]);

        return null;
    }
}
