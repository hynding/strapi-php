<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi;

use Strapi\Core\Registries\ActionMap;

/**
 * Not an upstream file: the object `createCoreController` / `createCoreService` build. Upstream
 * copies the base methods onto the user object and sets the base as its prototype, so user methods
 * override base ones and `this.sanitizeQuery(ctx)` reaches the base. Here the user overrides are
 * closures bound to this object (so `$this->sanitizeOutput(...)`, `$this->strapi` work inside them)
 * and every other call falls through to the base controller/service.
 */
final class Extendable
{
    /** @var array<string, \Closure> */
    private array $overrides = [];

    public readonly \Strapi\Core\Strapi $strapi;

    /** @param array<string, mixed> $overrides */
    public function __construct(private readonly object $base, array $overrides, \Strapi\Core\Strapi $strapi, public readonly bool $isCustom)
    {
        $this->strapi = $strapi;
        foreach ($overrides as $name => $fn) {
            if (!is_callable($fn)) {
                continue;
            }
            $closure = \Closure::fromCallable($fn);
            // bind closures to this facade so `$this` resolves the base methods too
            try {
                $bound = \Closure::bind($closure, $this, self::class);
                $this->overrides[(string) $name] = $bound ?? $closure;
            } catch (\Throwable) {
                $this->overrides[(string) $name] = $closure;
            }
        }
    }

    public function base(): object
    {
        return $this->base;
    }

    public function has(string $name): bool
    {
        return isset($this->overrides[$name]) || ActionMap::hasAction($this->base, $name);
    }

    /** @return list<string> */
    public function actionNames(): array
    {
        return array_values(array_unique([...array_keys($this->overrides), ...ActionMap::actionNames($this->base)]));
    }

    /** @param list<mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        if (isset($this->overrides[$name])) {
            return ($this->overrides[$name])(...$args);
        }

        if (ActionMap::hasAction($this->base, $name)) {
            return ActionMap::action($this->base, $name)(...$args);
        }

        throw new \BadMethodCallException("Method {$name} does not exist on " . get_class($this->base));
    }

    public function __get(string $name): mixed
    {
        if (isset($this->overrides[$name])) {
            return $this->overrides[$name];
        }

        return $this->base->{$name} ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->overrides[$name]) || isset($this->base->{$name});
    }
}
