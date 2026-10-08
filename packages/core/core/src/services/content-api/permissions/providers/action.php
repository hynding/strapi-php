<?php

declare(strict_types=1);

namespace Strapi\Core\Services\ContentApi\Permissions\Providers;

use Strapi\Core\Strapi;
use Strapi\Utils\ProviderFactory;

/**
 * Port of packages/core/core/src/services/content-api/permissions/providers/action.ts: a
 * {@see ProviderFactory} that refuses registrations once Strapi is loaded.
 */
/** @extends ProviderFactory<mixed> */
final class Action extends ProviderFactory
{
    /** @param array{throwOnDuplicates?: bool} $options */
    public function __construct(private readonly Strapi $strapi, array $options = [])
    {
        parent::__construct($options);
    }

    /** @param array{throwOnDuplicates?: bool} $options */
    public static function createActionProvider(Strapi $strapi, array $options = []): self
    {
        return new self($strapi, $options);
    }

    /** @param array<string, mixed> $payload */
    public function register(string $key, mixed $payload = null): static
    {
        if ($this->strapi->isLoaded()) {
            throw new \RuntimeException("You can't register new actions outside the bootstrap function.");
        }

        return parent::register($key, $payload);
    }
}
