<?php

declare(strict_types=1);

namespace Strapi\Admin\Domain\Condition;

use Strapi\Core\Core;
use Strapi\Utils\ProviderFactory;

/**
 * Port of server/src/domain/condition/provider.ts (`createConditionProvider()`): a
 * {@see ProviderFactory} of conditions whose `register()` takes create-condition attributes.
 *
 * Upstream spreads the provider factory into a new object; here the provider is wrapped and its
 * methods forwarded. `$isLoaded` replaces the read of the global `strapi.isLoaded`.
 */
final class Provider
{
    /** @var ProviderFactory<array<string, mixed>> */
    private readonly ProviderFactory $provider;

    /** @var array{willRegister: \Strapi\Utils\Hooks\AsyncSeriesHook, didRegister: \Strapi\Utils\Hooks\AsyncParallelHook, willDelete: \Strapi\Utils\Hooks\AsyncParallelHook, didDelete: \Strapi\Utils\Hooks\AsyncParallelHook} */
    public readonly array $hooks;

    /** @var \Closure(): bool */
    private readonly \Closure $isLoaded;

    /** @param (callable(): bool)|null $isLoaded */
    public function __construct(?callable $isLoaded = null)
    {
        /** @var ProviderFactory<array<string, mixed>> $provider */
        $provider = new ProviderFactory();
        $this->provider = $provider;
        $this->hooks = $this->provider->hooks;
        $this->isLoaded = $isLoaded !== null
            ? $isLoaded(...)
            : static fn (): bool => Core::instance()?->isLoaded() ?? false;
    }

    public static function createConditionProvider(?callable $isLoaded = null): self
    {
        return new self($isLoaded);
    }

    /**
     * @param array<string, mixed> $conditionAttributes
     * @return $this
     */
    public function register(array $conditionAttributes): static
    {
        if (($this->isLoaded)()) {
            throw new \RuntimeException("You can't register new conditions outside of the bootstrap function.");
        }

        $condition = Condition::create($conditionAttributes);

        $this->provider->register((string) $condition['id'], $condition);

        return $this;
    }

    /**
     * @param list<array<string, mixed>> $conditionsAttributes
     * @return $this
     */
    public function registerMany(array $conditionsAttributes): static
    {
        foreach ($conditionsAttributes as $attributes) {
            $this->register($attributes);
        }

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        return $this->provider->get($key);
    }

    /** @return list<array<string, mixed>> */
    public function values(): array
    {
        return $this->provider->values();
    }

    /** @return list<string> */
    public function keys(): array
    {
        return $this->provider->keys();
    }

    public function has(string $key): bool
    {
        return $this->provider->has($key);
    }

    public function size(): int
    {
        return $this->provider->size();
    }

    /** @return $this */
    public function delete(string $key): static
    {
        $this->provider->delete($key);

        return $this;
    }

    /** @return $this */
    public function clear(): static
    {
        $this->provider->clear();

        return $this;
    }
}
