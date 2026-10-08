<?php

declare(strict_types=1);

namespace Strapi\Openapi\Context;

use Strapi\Core\Strapi;
use Strapi\Openapi\Registries\Registries;
use Strapi\Openapi\Utils\Timer\Timer;

/**
 * Port of packages/core/openapi/src/context/types.ts: `Context<T>` (with its `output`), a class so
 * assemblers fill `$context->output->data` in place like upstream mutates `context.output.data`.
 *
 * `strapi` is typed structurally (upstream tests pass partial mocks `as Core.Strapi`): anything
 * with the {@see Strapi} methods the assemblers call (`config()`, `contentAPISchemaRegistry()`,
 * `apis()`, `plugins()`, `admin()`).
 *
 * @phpstan-type TimeStats array{startTime: int, endTime: int, elapsedTime: int}
 * @phpstan-type Stats array{time: TimeStats}
 * @phpstan-type PartialContext array{strapi: Strapi, routes: list<array<string, mixed>>, timer?: Timer, registries?: Registries}
 */
final class Context
{
    /**
     * @param Strapi $strapi
     * @param list<array<string, mixed>> $routes
     */
    public function __construct(
        public readonly object $strapi,
        public readonly array $routes,
        public readonly Timer $timer,
        public readonly Registries $registries,
        public readonly ContextOutput $output,
    ) {
    }
}
