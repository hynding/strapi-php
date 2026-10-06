<?php

declare(strict_types=1);

namespace Strapi\Core\Providers;

use Strapi\Core\Services\ContentStructure\ContentStructure as ContentStructureService;
use Strapi\Core\Strapi;

/** Port of packages/core/core/src/providers/content-structure.ts (the service is a stub, see services/content-structure). */
final class ContentStructure extends AbstractProvider
{
    public function init(Strapi $strapi): void
    {
        $strapi->add('content-structure', static fn (): ContentStructureService => ContentStructureService::createContentStructureService($strapi));
    }

    public function bootstrap(Strapi $strapi): void
    {
        $contentStructure = $strapi->get('content-structure');

        // Executes tolerant validation during bootstrap, logs validation warnings
        $cleaned = $contentStructure->getCleanedFile();

        if ($cleaned !== null) {
            $count = $contentStructure->countGroups();
            $strapi->log()->info("[content-structure] Loaded groups.json with {$count} folder group(s)");
        }
    }
}
