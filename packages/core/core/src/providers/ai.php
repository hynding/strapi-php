<?php

declare(strict_types=1);

namespace Strapi\Core\Providers;

use Strapi\Core\Services\Ai as AiService;
use Strapi\Core\Strapi;

/** Port of packages/core/core/src/providers/ai.ts (the AI namespace is a stub). */
final class Ai extends AbstractProvider
{
    public function init(Strapi $strapi): void
    {
        $strapi->add('ai', static fn (): AiService => AiService::createAiNamespace($strapi));
    }
}
