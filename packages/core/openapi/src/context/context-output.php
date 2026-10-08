<?php

declare(strict_types=1);

namespace Strapi\Openapi\Context;

/**
 * PHP-port addition: `ContextOutput<T>` of packages/core/openapi/src/context/types.ts as a class
 * (`{ data, stats }`), so assemblers write `$context->output->data` in place.
 *
 * @phpstan-import-type Stats from Context
 */
final class ContextOutput
{
    /**
     * @param array<string, mixed> $data
     * @param Stats $stats
     */
    public function __construct(
        public array $data = [],
        public array $stats = ['time' => ['startTime' => 0, 'endTime' => 0, 'elapsedTime' => 0]],
    ) {
    }
}
