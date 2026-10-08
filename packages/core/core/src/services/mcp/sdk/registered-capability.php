<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Sdk;

/**
 * Not an upstream file: the handle `McpServer.registerTool/registerPrompt/registerResource` return
 * (`RegisteredTool`, `RegisteredPrompt`, `RegisteredResource` of `@modelcontextprotocol/server`),
 * reduced to what Strapi's capability registries use: `enabled`, `enable()`, `disable()`,
 * `remove()`. A disabled capability is not listed and calling it is refused.
 */
class RegisteredCapability
{
    public bool $enabled = true;

    /** @var (\Closure(): void)|null */
    private ?\Closure $onRemove;

    /** @param (\Closure(): void)|null $onRemove */
    public function __construct(?\Closure $onRemove = null)
    {
        $this->onRemove = $onRemove;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function remove(): void
    {
        if ($this->onRemove !== null) {
            ($this->onRemove)();
        }
    }
}
