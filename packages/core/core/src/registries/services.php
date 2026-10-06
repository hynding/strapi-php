<?php

declare(strict_types=1);

namespace Strapi\Core\Registries;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/registries/services.ts. Same factory shapes as {@see Controllers}.
 */
final class Services
{
    /** @var array<string, mixed> */
    private array $services = [];

    /** @var array<string, object> */
    private array $instantiatedServices = [];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->services);
    }

    public function get(string $uid): ?object
    {
        if (isset($this->instantiatedServices[$uid])) {
            return $this->instantiatedServices[$uid];
        }

        if (!array_key_exists($uid, $this->services)) {
            return null;
        }

        $service = $this->services[$uid];
        $instance = $service instanceof \Closure || (is_object($service) && is_callable($service) && !$service instanceof ActionMap)
            ? $service($this->strapi)
            : $service;

        $this->instantiatedServices[$uid] = Controllers::toObject($instance);

        return $this->instantiatedServices[$uid];
    }

    /** @return array<string, object> */
    public function getAll(string $namespace = ''): array
    {
        $map = [];
        foreach (array_keys($this->services) as $uid) {
            if (Namespace_::hasNamespace($uid, $namespace)) {
                $service = $this->get($uid);
                if ($service !== null) {
                    $map[$uid] = $service;
                }
            }
        }

        return $map;
    }

    public function set(string $uid, mixed $service): static
    {
        $this->services[$uid] = $service;
        unset($this->instantiatedServices[$uid]);

        return $this;
    }

    /** @param array<string, mixed> $newServices */
    public function add(string $namespace, array $newServices): static
    {
        foreach ($newServices as $serviceName => $service) {
            $uid = Namespace_::addNamespace((string) $serviceName, $namespace);

            if (array_key_exists($uid, $this->services)) {
                throw new \RuntimeException("Service {$uid} has already been registered.");
            }
            $this->services[$uid] = $service;
        }

        return $this;
    }

    /** @param callable(object): object $extendFn */
    public function extend(string $uid, callable $extendFn): static
    {
        $currentService = $this->get($uid);

        if ($currentService === null) {
            throw new \RuntimeException("Service {$uid} doesn't exist");
        }

        $this->instantiatedServices[$uid] = Controllers::toObject($extendFn($currentService));

        return $this;
    }
}
