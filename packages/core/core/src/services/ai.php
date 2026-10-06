<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/services/ai.ts.
 *
 * STUB (TODO): upstream builds the `strapi.ai` namespace (with `mcp`). Only `mcp` is exposed here.
 */
final class Ai
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public static function createAiNamespace(Strapi $strapi): self
    {
        return new self($strapi);
    }

    public function mcp(): Mcp\Mcp
    {
        return $this->strapi->get('ai.mcp');
    }
}
