<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Services\Builders\Filters\Operators\Operator;
use Strapi\Plugin\Graphql\Services\Builders\Filters\Operators\Operators;
use Strapi\Plugin\Graphql\Services\TypeRegistry;

/**
 * Port of server/src/services/builders/index.ts: the `builders` service. `new(name, registry)`
 * instantiates every builder with the type registry and merges them into one object
 * ({@see BuildersInstance}).
 */
final class Builders
{
    /** @var array<string, BuildersInstance> */
    private array $buildersMap = [];

    /** @var object{operators: array<string, Operator>} */
    public readonly object $filters;

    public readonly Utils $utils;

    public function __construct(private readonly Strapi $strapi)
    {
        $this->filters = new class (Operators::create($strapi)) {
            /** @param array<string, Operator> $operators */
            public function __construct(public readonly array $operators)
            {
            }
        };
        $this->utils = new Utils($strapi);
    }

    /**
     * Instantiate every builder with a strapi instance & a type registry
     */
    public function new(string $name, TypeRegistry $registry): BuildersInstance
    {
        $builders = new BuildersInstance($this->strapi, $registry);

        $this->buildersMap[$name] = $builders;

        return $builders;
    }

    /**
     * Delete a set of builders instances from
     * the builders map for a given name
     */
    public function delete(string $name): void
    {
        unset($this->buildersMap[$name]);
    }

    /**
     * Retrieve a set of builders instances from
     * the builders map for a given name
     */
    public function get(string $name): ?BuildersInstance
    {
        return $this->buildersMap[$name] ?? null;
    }
}
