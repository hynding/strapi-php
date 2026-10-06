<?php

declare(strict_types=1);

namespace Strapi\Core\Registries;

/**
 * Port of packages/core/core/src/registries/middlewares.ts. A registered middleware is a factory:
 * `callable(array $config, Strapi $strapi): ?callable` (see Strapi\Types\Core\MiddlewareFactory).
 */
final class Middlewares
{
    /** @var array<string, callable> */
    private array $middlewares = [];

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->middlewares);
    }

    public function get(string $uid): ?callable
    {
        return $this->middlewares[$uid] ?? null;
    }

    /** @return array<string, callable> */
    public function getAll(string $namespace = ''): array
    {
        return array_filter($this->middlewares, static fn (string $uid): bool => Namespace_::hasNamespace($uid, $namespace), ARRAY_FILTER_USE_KEY);
    }

    public function set(string $uid, callable $middleware): static
    {
        $this->middlewares[$uid] = $middleware;

        return $this;
    }

    /** @param array<string, callable> $rawMiddlewares */
    public function add(string $namespace, array $rawMiddlewares = []): void
    {
        foreach ($rawMiddlewares as $middlewareName => $middleware) {
            $uid = Namespace_::addNamespace((string) $middlewareName, $namespace);

            if (array_key_exists($uid, $this->middlewares)) {
                throw new \RuntimeException("Middleware {$uid} has already been registered.");
            }
            $this->middlewares[$uid] = $middleware;
        }
    }

    /** @param callable(callable): callable $extendFn */
    public function extend(string $uid, callable $extendFn): static
    {
        $currentMiddleware = $this->get($uid);

        if ($currentMiddleware === null) {
            throw new \RuntimeException("Middleware {$uid} doesn't exist");
        }

        $this->middlewares[$uid] = $extendFn($currentMiddleware);

        return $this;
    }
}
