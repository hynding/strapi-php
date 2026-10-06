<?php

declare(strict_types=1);

namespace Strapi\Core\Registries;

/**
 * Not an upstream file. Upstream controllers and services are plain objects with function
 * properties (`{ find(ctx) {...} }`). In PHP a user may write them as `['find' => fn ($ctx) => ...]`;
 * the registries wrap such arrays in an ActionMap so `$controller->find($ctx)` and
 * `method_exists`-style introspection (`has()`) work the same as for class instances.
 */
final class ActionMap
{
    /** @var array<string, callable> */
    private array $actions = [];

    /** @param array<string, mixed> $actions */
    public function __construct(array $actions = [])
    {
        foreach ($actions as $name => $action) {
            if (is_callable($action)) {
                $this->actions[(string) $name] = $action;
            }
        }
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->actions);
    }

    public function set(string $name, callable $action): void
    {
        $this->actions[$name] = $action;
    }

    /** @return array<string, callable> */
    public function all(): array
    {
        return $this->actions;
    }

    /** @param list<mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        if (!$this->has($name)) {
            throw new \BadMethodCallException("Action \"{$name}\" is not defined");
        }

        return ($this->actions[$name])(...$args);
    }

    public function __get(string $name): mixed
    {
        return $this->actions[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return $this->has($name);
    }

    /** Names of the callable actions of any controller-like object (ActionMap or class instance). */
    public static function actionNames(object $controller): array
    {
        if ($controller instanceof self) {
            return array_keys($controller->actions);
        }
        if ($controller instanceof \Strapi\Core\CoreApi\Extendable) {
            return $controller->actionNames();
        }

        $names = [];
        foreach ((new \ReflectionObject($controller))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isStatic() && !$method->isConstructor() && !str_starts_with($method->getName(), '__')) {
                $names[] = $method->getName();
            }
        }

        return $names;
    }

    public static function hasAction(object $controller, string $name): bool
    {
        if ($controller instanceof self) {
            return $controller->has($name);
        }
        if ($controller instanceof \Strapi\Core\CoreApi\Extendable) {
            return $controller->has($name);
        }

        return method_exists($controller, $name) || (property_exists($controller, $name) && is_callable($controller->{$name}));
    }

    /** @return callable */
    public static function action(object $controller, string $name): callable
    {
        if ($controller instanceof self) {
            return $controller->actions[$name];
        }
        if ($controller instanceof \Strapi\Core\CoreApi\Extendable) {
            return static fn (mixed ...$args): mixed => $controller->{$name}(...$args);
        }
        if (method_exists($controller, $name)) {
            return [$controller, $name];
        }

        /** @var callable $cb */
        $cb = $controller->{$name};

        return $cb;
    }
}
