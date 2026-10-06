<?php

declare(strict_types=1);

namespace Strapi\Database\Lifecycles;

/**
 * The lifecycle event (upstream `Event`): `{ action, model, params, result, state }`.
 * `params`, `result` and `state` are mutable so subscribers can alter them (timestamps do).
 *
 * @phpstan-import-type Meta from \Strapi\Database\Metadata\Metadata
 */
final class Event
{
    /**
     * @param Meta $model
     * @param array<string, mixed> $params
     * @param array<string, mixed> $state
     */
    public function __construct(
        public readonly string $action,
        public array $model,
        public array $params,
        public array $state = [],
        public mixed $result = null,
        public readonly bool $hasResult = false,
    ) {
    }
}
