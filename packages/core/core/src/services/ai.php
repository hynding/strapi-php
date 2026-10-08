<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/services/ai.ts.
 *
 * The `strapi.ai` namespace: `admin` (registered by the admin package as `ai.admin`) and `mcp`.
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

    /** `strapi.ai.admin`: the admin AI service (`ai.admin`, registered by the admin package). */
    public function admin(): object
    {
        return $this->strapi->get('ai.admin');
    }

    public function mcp(): Mcp\Mcp
    {
        return $this->strapi->get('ai.mcp');
    }
}
