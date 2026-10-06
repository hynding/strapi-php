<?php

declare(strict_types=1);

namespace Strapi\Core\Registries;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/registries/controllers.ts.
 *
 * A controller factory is `callable(Strapi $strapi): object|array`, an object, or an array of
 * actions (`['find' => fn (Context $ctx) => ...]`, wrapped into an {@see ActionMap}).
 */
final class Controllers
{
    /** @var array<string, mixed> */
    private array $controllers = [];

    /** @var array<string, object> */
    private array $instances = [];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->controllers);
    }

    /** Returns the instance of a controller. Instantiate the controller if not already done. */
    public function get(string $uid): ?object
    {
        if (isset($this->instances[$uid])) {
            return $this->instances[$uid];
        }

        if (!array_key_exists($uid, $this->controllers)) {
            return null;
        }

        $controller = $this->controllers[$uid];
        $instance = $controller instanceof \Closure || (is_object($controller) && is_callable($controller) && !$controller instanceof ActionMap)
            ? $controller($this->strapi)
            : $controller;

        $this->instances[$uid] = self::toObject($instance);

        return $this->instances[$uid];
    }

    /** @return array<string, object> every controller of the namespace, instantiated */
    public function getAll(string $namespace = ''): array
    {
        $map = [];
        foreach (array_keys($this->controllers) as $uid) {
            if (Namespace_::hasNamespace($uid, $namespace)) {
                $controller = $this->get($uid);
                if ($controller !== null) {
                    $map[$uid] = $controller;
                }
            }
        }

        return $map;
    }

    public function set(string $uid, mixed $value): static
    {
        $this->controllers[$uid] = $value;
        unset($this->instances[$uid]);

        return $this;
    }

    /** @param array<string, mixed> $newControllers */
    public function add(string $namespace, array $newControllers): static
    {
        foreach ($newControllers as $controllerName => $controller) {
            $uid = Namespace_::addNamespace((string) $controllerName, $namespace);

            if (array_key_exists($uid, $this->controllers)) {
                throw new \RuntimeException("Controller {$uid} has already been registered.");
            }

            $this->controllers[$uid] = $controller;
        }

        return $this;
    }

    /** @param callable(object): object $extendFn */
    public function extend(string $controllerUID, callable $extendFn): static
    {
        $currentController = $this->get($controllerUID);

        if ($currentController === null) {
            throw new \RuntimeException("Controller {$controllerUID} doesn't exist");
        }

        $this->instances[$controllerUID] = self::toObject($extendFn($currentController));

        return $this;
    }

    public static function toObject(mixed $instance): object
    {
        if (is_array($instance)) {
            return new ActionMap($instance);
        }
        if (!is_object($instance)) {
            throw new \RuntimeException('A controller must be an object or an array of actions, got ' . get_debug_type($instance));
        }

        return $instance;
    }
}
