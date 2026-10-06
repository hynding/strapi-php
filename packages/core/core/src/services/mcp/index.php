<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/services/mcp/index.ts.
 *
 * STUB (TODO): the Model Context Protocol server (JSON-RPC over HTTP, capability registries,
 * OAuth discovery) is not ported. `isEnabled()` reads `server.mcp.enabled`; `start()`/`stop()` are no-ops.
 */
final class Mcp
{
    private bool $running = false;

    public function __construct(private readonly Strapi $strapi)
    {
    }

    public static function createMcpService(Strapi $strapi): self
    {
        return new self($strapi);
    }

    public function isEnabled(): bool
    {
        return $this->strapi->config()->get('server.mcp.enabled', false) === true;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function start(): void
    {
        $this->running = true;
    }

    public function stop(): void
    {
        $this->running = false;
    }
}
