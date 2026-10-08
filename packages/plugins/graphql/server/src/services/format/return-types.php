<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Format;

/**
 * Port of server/src/services/format/return-types.ts: the intermediate values the entity
 * response / response collection types resolve from.
 *
 * @phpstan-type InfoType array{args?: mixed, resourceUID?: string|null}
 */
final class ReturnTypes
{
    /**
     * @param InfoType $info
     * @return array{value: mixed, info: array{args: mixed, resourceUID: string|null}}
     */
    public function toEntityResponse(mixed $value, array $info = []): array
    {
        $args = $info['args'] ?? [];
        $resourceUID = $info['resourceUID'] ?? null;

        return ['value' => $value, 'info' => ['args' => $args, 'resourceUID' => $resourceUID]];
    }

    /**
     * @param InfoType $info
     * @return array{nodes: mixed, info: array{args: mixed, resourceUID: string|null}}
     */
    public function toEntityResponseCollection(mixed $nodes, array $info = []): array
    {
        $args = $info['args'] ?? [];
        $resourceUID = $info['resourceUID'] ?? null;

        return ['nodes' => $nodes, 'info' => ['args' => $args, 'resourceUID' => $resourceUID]];
    }
}
