<?php

declare(strict_types=1);

namespace Strapi\Core\Registries;

/**
 * Port of packages/core/core/src/registries/models.ts: raw database models (core store, webhooks)
 * that are not content types.
 *
 * @phpstan-import-type Model from \Strapi\Database\Metadata\Metadata
 */
final class Models
{
    /** @var list<Model> */
    private array $models = [];

    /** @param Model $model */
    public function add(array $model): static
    {
        $this->models[] = $model;

        return $this;
    }

    /** @return list<Model> */
    public function get(): array
    {
        return $this->models;
    }
}
