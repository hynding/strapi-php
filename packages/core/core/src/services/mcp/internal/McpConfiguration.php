<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Internal;

use Strapi\Core\Strapi;

/** Port of services/mcp/internal/McpConfiguration.ts. */
final class McpConfiguration
{
    public readonly string $path;

    public readonly int|float $connectTimeoutMs;

    public readonly int|float $requestTimeoutMs;

    public function __construct(private readonly Strapi $strapi)
    {
        $this->path = '/mcp';
        $connect = $strapi->config()->get('server.mcp.connectTimeoutMs', 5 * 1000); // 5 seconds
        $request = $strapi->config()->get('server.mcp.requestTimeoutMs', 60 * 1000); // 60 seconds
        $this->connectTimeoutMs = is_int($connect) || is_float($connect) ? $connect : 5 * 1000;
        $this->requestTimeoutMs = is_int($request) || is_float($request) ? $request : 60 * 1000;
    }

    public function isEnabled(): bool
    {
        return $this->strapi->config()->get('server.mcp.enabled', false) === true;
    }

    public function isDevMode(): bool
    {
        return $this->strapi->config()->get('autoReload', false) === true;
    }
}
