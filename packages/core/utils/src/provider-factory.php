<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Utils\Hooks\AsyncParallelHook;
use Strapi\Utils\Hooks\AsyncSeriesHook;

/**
 * Port of packages/core/utils/src/provider-factory.ts: a keyed registry with lifecycle hooks.
 * Upstream exports a factory function; here `new ProviderFactory($options)` or `ProviderFactory::create()`.
 *
 * @template T
 */
class ProviderFactory
{
    /** @var array{willRegister: AsyncSeriesHook, didRegister: AsyncParallelHook, willDelete: AsyncParallelHook, didDelete: AsyncParallelHook} */
    public readonly array $hooks;

    /** @var array<string, T> */
    private array $registry = [];

    private readonly bool $throwOnDuplicates;

    /** @param array{throwOnDuplicates?: bool} $options */
    public function __construct(array $options = [])
    {
        $this->throwOnDuplicates = $options['throwOnDuplicates'] ?? true;
        $this->hooks = [
            'willRegister' => Hooks::createAsyncSeriesHook(),
            'didRegister' => Hooks::createAsyncParallelHook(),
            'willDelete' => Hooks::createAsyncParallelHook(),
            'didDelete' => Hooks::createAsyncParallelHook(),
        ];
    }

    /**
     * @param array{throwOnDuplicates?: bool} $options
     * @return self<mixed>
     */
    public static function create(array $options = []): self
    {
        return new self($options);
    }

    /**
     * @param T $item
     * @return $this
     */
    public function register(string $key, mixed $item): static
    {
        if ($this->throwOnDuplicates && $this->has($key)) {
            throw new \RuntimeException("Duplicated item key: {$key}");
        }

        // The series hook receives the item by reference so handlers can mutate it (as upstream, where objects are references).
        $context = new HookContext($key, $item);
        $this->hooks['willRegister']->call($context);
        $item = $context->value;

        $this->registry[$key] = $item;

        $this->hooks['didRegister']->call(['key' => $key, 'value' => AsyncParallelHook::cloneDeep($item)]);

        return $this;
    }

    /** @return $this */
    public function delete(string $key): static
    {
        if ($this->has($key)) {
            $item = $this->get($key);

            $this->hooks['willDelete']->call(['key' => $key, 'value' => AsyncParallelHook::cloneDeep($item)]);

            unset($this->registry[$key]);

            $this->hooks['didDelete']->call(['key' => $key, 'value' => AsyncParallelHook::cloneDeep($item)]);
        }

        return $this;
    }

    /** @return T|null */
    public function get(string $key): mixed
    {
        return $this->registry[$key] ?? null;
    }

    /** @return list<T> */
    public function values(): array
    {
        return array_values($this->registry);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->registry);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->registry);
    }

    public function size(): int
    {
        return count($this->registry);
    }

    /** @return $this */
    public function clear(): static
    {
        foreach ($this->keys() as $key) {
            $this->delete($key);
        }

        return $this;
    }
}
