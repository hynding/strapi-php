<?php

declare(strict_types=1);

namespace Strapi\Utils;

/**
 * Port of packages/core/utils/src/content-api-router.ts: a content-api route factory that exposes
 * `routes` on the factory for backward compatibility.
 *
 * This allows legacy extensions to mutate `plugin.routes["content-api"].routes` directly. The JS
 * factory is a function with a `routes` accessor property; here it is an invokable object with a
 * `routes` property (read lazily, assignable).
 *
 * @template TRoutes
 * @property TRoutes $routes
 */
final class ContentApiRouter
{
    /** @var \Closure(): TRoutes */
    private readonly \Closure $buildRoutes;

    private bool $built = false;

    /** @var TRoutes|null */
    private mixed $sharedRoutes = null;

    /** @param callable(): TRoutes $buildRoutes */
    private function __construct(callable $buildRoutes)
    {
        $this->buildRoutes = \Closure::fromCallable($buildRoutes);
    }

    /**
     * @template T
     * @param callable(): T $buildRoutes
     * @return self<T>
     */
    public static function createContentApiRoutesFactory(callable $buildRoutes): self
    {
        return new self($buildRoutes);
    }

    /** @return array{type: 'content-api', routes: TRoutes} */
    public function __invoke(): array
    {
        return [
            'type' => 'content-api',
            'routes' => $this->ensureSharedRoutes(),
        ];
    }

    public function __get(string $name): mixed
    {
        if ($name !== 'routes') {
            throw new \LogicException("Undefined property {$name}");
        }

        return $this->ensureSharedRoutes();
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name !== 'routes') {
            throw new \LogicException("Undefined property {$name}");
        }
        $this->sharedRoutes = $value;
        $this->built = true;
    }

    public function __isset(string $name): bool
    {
        return $name === 'routes';
    }

    /** @return TRoutes */
    private function ensureSharedRoutes(): mixed
    {
        if (!$this->built) {
            $this->sharedRoutes = ($this->buildRoutes)();
            $this->built = true;
        }

        /** @var TRoutes */
        return $this->sharedRoutes;
    }
}
