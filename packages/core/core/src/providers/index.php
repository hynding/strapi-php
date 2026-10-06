<?php

declare(strict_types=1);

namespace Strapi\Core\Providers;

/** Port of packages/core/core/src/providers/index.ts: the ordered provider list. */
final class Providers
{
    /** @return list<Provider> */
    public static function all(): array
    {
        return [
            new Registries(),
            new Admin(),
            new Ai(),
            new ContentStructure(),
            new CoreStore(),
            new SessionManager(),
            new Webhooks(),
            new Telemetry(),
            new Cron(),
            new Mcp(),
        ];
    }
}
