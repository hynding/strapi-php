<?php

declare(strict_types=1);

namespace Strapi\Core\Registries;

use Strapi\Core\Domain\Module\Module;
use Strapi\Core\Strapi;

/** Port of packages/core/core/src/registries/apis.ts. */
final class Apis
{
    /** @var array<string, Module> */
    private array $apis = [];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function get(string $name): ?Module
    {
        return $this->apis[$name] ?? null;
    }

    /** @return array<string, Module> */
    public function getAll(): array
    {
        return $this->apis;
    }

    /** @param array<string, mixed> $apiConfig */
    public function add(string $apiName, array $apiConfig): Module
    {
        if (array_key_exists($apiName, $this->apis)) {
            throw new \RuntimeException("API {$apiName} has already been registered.");
        }

        $api = $this->strapi->get('modules')->add("api::{$apiName}", $apiConfig);

        $this->apis[$apiName] = $api;

        return $api;
    }
}
