<?php

declare(strict_types=1);

namespace Strapi\ApiTests;

/**
 * An object of the test process assigned to a property of the instance (an `assign` step, see
 * {@see Bridge}): `strapi.plugin('upload').provider = { ...originalProvider, async uploadStream(file) {} }`.
 * Its functions are {@see Callback}s, called with the JSON-exported arguments; what a function
 * changes in an array-like argument (`file.url = ...`) is copied back into it. Any other method
 * or property is the original object's, as the spread copies them upstream.
 */
final class RemoteObject
{
    /**
     * @param array<string, Callback> $methods
     * @param array<string, mixed> $values
     */
    public function __construct(public readonly mixed $original, private readonly array $methods, private readonly array $values = [])
    {
    }

    /** @param list<mixed> $args */
    public function __call(string $name, array $args): mixed
    {
        $callback = $this->methods[$name] ?? null;
        if ($callback === null) {
            if (!is_object($this->original)) {
                throw new \BadMethodCallException("Call to undefined method {$name}()");
            }

            return $this->original->{$name}(...$args);
        }

        $args = array_values($args);
        $before = Bridge::export($args);
        ['result' => $result, 'args' => $after] = $callback->call($args);

        foreach ($args as $i => $arg) {
            if (!$arg instanceof \ArrayAccess || !is_array($after[$i] ?? null) || !is_array($before[$i] ?? null)) {
                continue;
            }
            foreach ($after[$i] as $key => $value) {
                if (!array_key_exists($key, $before[$i]) || $before[$i][$key] !== $value) {
                    $arg[$key] = $value;
                }
            }
        }

        return $result;
    }

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->values)) {
            return $this->values[$name];
        }

        return is_object($this->original) ? $this->original->{$name} : null;
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->values) || (is_object($this->original) && isset($this->original->{$name}));
    }
}
