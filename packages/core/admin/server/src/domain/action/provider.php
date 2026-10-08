<?php

declare(strict_types=1);

namespace Strapi\Admin\Domain\Action;

use Strapi\Admin\Validation\ActionProvider as ActionProviderValidation;
use Strapi\Core\Core;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Hooks;
use Strapi\Utils\Hooks\AsyncParallelHook;
use Strapi\Utils\ProviderFactory;

/**
 * Port of server/src/domain/action/provider.ts (`createActionProvider(options)`): a
 * {@see ProviderFactory} of actions whose `register()` takes create-action attributes.
 *
 * Upstream spreads the provider factory into a new object; here the provider is wrapped and its
 * methods (`get`, `values`, `keys`, `has`, `size`, `delete`, `clear`) are forwarded.
 * `$isLoaded` replaces upstream's read of the global `strapi.isLoaded` (defaults to the current
 * Strapi instance).
 */
final class Provider
{
    /** @var ProviderFactory<array<string, mixed>> */
    private readonly ProviderFactory $provider;

    /** @var array{willRegister: \Strapi\Utils\Hooks\AsyncSeriesHook, didRegister: AsyncParallelHook, willDelete: AsyncParallelHook, didDelete: AsyncParallelHook, appliesPropertyToSubject: AsyncParallelHook} */
    public readonly array $hooks;

    /** @var \Closure(): bool */
    private readonly \Closure $isLoaded;

    /**
     * @param array{throwOnDuplicates?: bool} $options
     * @param (callable(): bool)|null $isLoaded
     */
    public function __construct(array $options = [], ?callable $isLoaded = null)
    {
        /** @var ProviderFactory<array<string, mixed>> $provider */
        $provider = new ProviderFactory($options);
        $this->provider = $provider;
        $this->hooks = [
            ...$this->provider->hooks,
            'appliesPropertyToSubject' => Hooks::createAsyncParallelHook(),
        ];
        $this->isLoaded = $isLoaded !== null
            ? $isLoaded(...)
            : static fn (): bool => Core::instance()?->isLoaded() ?? false;
    }

    /** @param array{throwOnDuplicates?: bool} $options */
    public static function createActionProvider(array $options = [], ?callable $isLoaded = null): self
    {
        return new self($options, $isLoaded);
    }

    /**
     * @param array<string, mixed> $actionAttributes
     * @return $this
     */
    public function register(array $actionAttributes): static
    {
        if (($this->isLoaded)()) {
            throw new \RuntimeException("You can't register new actions outside of the bootstrap function.");
        }

        ActionProviderValidation::validateRegisterProviderAction([$actionAttributes]);

        $action = Action::create($actionAttributes);

        $this->provider->register((string) $action['actionId'], $action);

        return $this;
    }

    /**
     * @param list<array<string, mixed>> $actionsAttributes
     * @return $this
     */
    public function registerMany(array $actionsAttributes): static
    {
        ActionProviderValidation::validateRegisterProviderAction($actionsAttributes);

        foreach ($actionsAttributes as $attributes) {
            $this->register($attributes);
        }

        return $this;
    }

    public function appliesToProperty(string $property, string $actionId, ?string $subject = null): bool
    {
        $action = $this->provider->get($actionId);
        if ($action === null) {
            throw new ApplicationError("No action found with id \"{$actionId}\"");
        }

        $appliesToAction = Action::appliesToProperty($property, $action);

        // If the property isn't valid for this action, ignore the rest of the checks
        if (!$appliesToAction) {
            return false;
        }

        // If the property is valid for this action and there isn't any subject
        if ($subject === null || $subject === '') {
            return true;
        }

        // If the property is valid for this action and the subject is not handled by the action
        if (!Action::appliesToSubject($subject, $action)) {
            return false;
        }

        $results = $this->hooks['appliesPropertyToSubject']->call([
            'property' => $property,
            'action' => $action,
            'subject' => $subject,
        ]);

        foreach (is_array($results) ? $results : [] as $result) {
            if ($result === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @experimental
     * @return list<string>
     */
    public function unstable_aliases(string $actionId, ?string $subject = null): array
    {
        if (!$this->has($actionId)) {
            return [];
        }

        $out = [];
        foreach ($this->values() as $action) {
            $aliases = $action['aliases'] ?? null;
            if (!is_array($aliases)) {
                continue;
            }

            foreach ($aliases as $alias) {
                // Only look at alias with the correct actionId
                if (($alias['actionId'] ?? null) !== $actionId) {
                    continue;
                }

                $subjects = $alias['subjects'] ?? null;

                // If the alias don't have a list of required subjects, keep it
                // If the alias require specific subjects but none is provided, skip it
                // Else, make sure the given subject is allowed
                if (!is_array($subjects) || ($subject !== null && $subject !== '' && in_array($subject, $subjects, true))) {
                    $out[] = (string) $action['actionId'];
                    break;
                }
            }
        }

        return $out;
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
