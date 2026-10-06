<?php

declare(strict_types=1);

namespace Strapi\Utils\Traverse;

/**
 * `{ set, remove }` handed to visitors. Traversals plug their own set/remove so that the same
 * visitor works on entities (arrays), sort strings, populate strings and `*` wildcards.
 */
final class VisitorUtils
{
    /** @var \Closure(string, mixed): void */
    private \Closure $setFn;

    /** @var \Closure(string): void */
    private \Closure $removeFn;

    private mixed $data;

    /**
     * @param callable(string, mixed, mixed): mixed|null $set    (key, value, data) → new data
     * @param callable(string, mixed): mixed|null $remove        (key, data) → new data
     */
    public function __construct(mixed $data, ?callable $set = null, ?callable $remove = null)
    {
        $this->data = $data;
        $this->setFn = $set !== null
            ? function (string $key, mixed $value) use ($set): void {
                $this->data = $set($key, $value, $this->data);
            }
        : function (string $key, mixed $value): void {
            if (is_array($this->data)) {
                $this->data[$key] = $value;
            }
        };
        $this->removeFn = $remove !== null
            ? function (string $key) use ($remove): void {
                $this->data = $remove($key, $this->data);
            }
        : function (string $key): void {
            if (is_array($this->data)) {
                unset($this->data[$key]);
            }
        };
    }

    public function set(string $key, mixed $value): void
    {
        ($this->setFn)($key, $value);
    }

    public function remove(string $key): void
    {
        ($this->removeFn)($key);
    }

    /** The node after the visitor's edits. */
    public function data(): mixed
    {
        return $this->data;
    }

    /** @internal traversals sync their working copy back after recursing. */
    public function replace(mixed $data): void
    {
        $this->data = $data;
    }
}
